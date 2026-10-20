<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Feed\{FeedService,FeedMixer,FeedInteractions};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Cache};
use Tests\TestCase;

class DiverseFeedTest extends TestCase
{
    private User $viewer;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','feed.cache_store'=>'array','feed.candidate_pool'=>20]);
        DB::purge('sqlite');
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('firebase_uid')->nullable();$t->string('legacy_firebase_uid')->nullable();$t->timestamps();});
        Schema::create('friendships',function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('friend_id');$t->string('status');});
        Schema::create('following',function(Blueprint $t){$t->id();$t->integer('user_id');$t->string('parent_id');$t->string('status');});
        Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->text('payload');$t->timestamps();});
        $migration=require database_path('migrations/2026_09_18_000300_add_diverse_feed.php');
        $migration->up();
        $this->viewer=User::create(['name'=>'Viewer','email'=>'viewer@example.invalid','firebase_uid'=>'viewer']);
        Cache::store('array')->flush();
    }
    private function seedPost(string $id,string $author,int $time,array $extra=[]): void
    {
        $payload=array_merge(['id'=>$id,'authorId'=>$author,'text'=>$id,'tags'=>['music'],'createdAtMs'=>$time],$extra);
        DB::table('firebase_documents')->insert(['collection_path'=>'posts','document_id'=>$id,'payload'=>json_encode($payload),
            'feed_author_id'=>$author,'feed_owner_id'=>$author,'feed_created_at_ms'=>$time,'feed_status'=>$extra['status'] ?? 'published',
            'feed_audience'=>$extra['audience'] ?? 'public','feed_tags'=>json_encode($payload['tags']),'feed_revision'=>md5(json_encode($payload)),
            'created_at'=>now(),'updated_at'=>now()]);
    }

    public function test_flood_is_diversified_without_losing_deferred_posts_or_tied_timestamps(): void
    {
        $time=now()->getTimestampMs()-10000;
        for($i=0;$i<130;$i++) $this->seedPost(sprintf('flood-%03d',$i),'flood',$time);
        foreach(['b','c','d'] as $author) $this->seedPost($author,$author,$time-1);
        $service=new FeedService;
        $page=$service->page($this->viewer,null,10);
        $ids=array_column($page['items'],'id');
        $this->assertContains('b',$ids);
        $this->assertContains('c',$ids);
        $this->assertContains('d',$ids);
        $this->seedPost('new-during-scroll','new',$time+1000);
        $next=$page['nextCursor'];$pages=0;
        while($next && $pages++<30){
            $page=$service->page($this->viewer,$next,10);
            $replay=$service->page($this->viewer,$next,10);
            $this->assertSame(array_column($page['items'],'id'),array_column($replay['items'],'id'));
            $ids=[...$ids,...array_column($page['items'],'id')];$next=$page['nextCursor'];
        }
        $this->assertNull($next);
        $this->assertCount(133,$ids);
        $this->assertCount(133,array_unique($ids));
        $this->assertNotContains('new-during-scroll',$ids);
    }

    public function test_slots_and_cross_page_author_streaks(): void
    {
        $candidates=[];
        for($i=0;$i<16;$i++) $candidates[]=['id'=>'p'.$i,'author'=>'a'.$i,'timestamp'=>100-$i,'score'=>100-$i,'suggestion'=>false,'reason'=>'friend'];
        for($i=0;$i<4;$i++) $candidates[]=['id'=>'s'.$i,'author'=>'s'.$i,'timestamp'=>100-$i,'score'=>100-$i,'suggestion'=>true,'reason'=>'discovery'];
        $mixed=(new FeedMixer)->page($candidates,20,[]);
        $slots=[];foreach($mixed['items'] as $i=>$item) if($item['feedItemType']==='SUGGESTION') $slots[]=$i;
        $this->assertSame([3,7,11,15],$slots);
        $mixed=(new FeedMixer)->page($candidates,1,['last_author'=>'a0','streak'=>2]);
        $this->assertNotSame('p0',$mixed['items'][0]['id']);
    }

    public function test_visibility_is_rechecked_and_revision_cache_cannot_serve_an_edited_post(): void
    {
        $time=now()->getTimestampMs()-1000;
        $this->seedPost('public','other',$time);
        $this->seedPost('private','other',$time,['audience'=>'private']);
        $this->seedPost('friends','other',$time,['audience'=>'friends']);
        $this->seedPost('draft','viewer',$time,['status'=>'draft']);
        $service=new FeedService;
        $first=$service->page($this->viewer,null,20);
        $this->assertSame(['public'],array_column($first['items'],'id'));
        $cursor=DB::table('feed_cursors')->value('id');
        DB::table('firebase_documents')->where('document_id','public')->update(['payload'=>json_encode(['text'=>'edited','id'=>'public']),'feed_revision'=>'revision-2']);
        $edited=$service->page($this->viewer,$cursor,20);
        $this->assertSame('edited',$edited['items'][0]['text']);
        DB::table('firebase_documents')->where('document_id','public')->update(['feed_audience'=>'private']);
        $this->assertSame([],$service->page($this->viewer,$cursor,20)['items']);
    }

    public function test_interactions_are_idempotent_use_server_tags_and_require_a_qualified_view(): void
    {
        $this->seedPost('post','other',now()->getTimestampMs()-1000,['tags'=>['music']]);
        $events=new FeedInteractions;
        $event=['eventId'=>'event-1','postId'=>'post','type'=>'VIEW','durationMs'=>100];
        $this->assertSame(0,$events->record($this->viewer,[$event]));
        $event['durationMs']=2000;$event['tags']=['forged'];
        $this->assertSame(1,$events->record($this->viewer,[$event]));
        $this->assertSame(0,$events->record($this->viewer,[$event]));
        $event['eventId']='event-2';
        $this->assertSame(0,$events->record($this->viewer,[$event]));
        $scores=json_decode(DB::table('feed_affinities')->value('tag_scores'),true);
        $this->assertEquals(1,$scores['music']);$this->assertArrayNotHasKey('forged',$scores);
    }

    public function test_cache_outage_falls_back_to_database_and_cursors_are_user_bound(): void
    {
        config(['feed.cache_store'=>'unavailable']);
        $this->seedPost('post','other',now()->getTimestampMs()-1000);
        $this->assertCount(1,(new FeedService)->page($this->viewer,null,20)['items']);
        $cursor=DB::table('feed_cursors')->value('id');
        $other=User::create(['name'=>'Other','email'=>'other@example.invalid','firebase_uid'=>'other']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new FeedService)->page($other,$cursor,20);
    }
}
