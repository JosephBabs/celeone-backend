<?php
namespace Tests\Feature;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class PublicPostTest extends TestCase {
 public function test_generated_video_preview_is_returned_for_sharing(): void {
  \Illuminate\Support\Facades\Storage::fake('public');
  $video='https://example.test/video.mp4';$key=\App\Services\PublicVideoPreview::key($video);
  \Illuminate\Support\Facades\Storage::disk('public')->put($key.'.jpg','frame');
  \Illuminate\Support\Facades\Storage::disk('public')->put($key.'-share.jpg','play');
  $result=\App\Services\PublicVideoPreview::decorate(['media'=>[['mime'=>'video/mp4','url'=>$video]]]);
  $this->assertStringEndsWith($key.'.jpg',$result['thumbnailUrl']);
  $this->assertStringEndsWith($key.'-share.jpg',$result['videoShareImage']);
 }
 public function test_preview_generator_rejects_untrusted_video_hosts(): void {
  $this->expectException(\RuntimeException::class);
  (new \App\Services\PublicVideoPreview)->generate('https://127.0.0.1/private.mp4');
 }
 protected function setUp(): void {parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');
 Schema::create('posts',function(Blueprint $t){$t->id();$t->string('firebase_id');$t->string('audience')->default('public');$t->string('status')->default('published');$t->text('legacy_data')->nullable();$t->text('text_content')->nullable();$t->timestamps();});
 Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->text('payload');});
 DB::table('posts')->insert(['id'=>123,'firebase_id'=>'legacy-post','audience'=>'public','status'=>'published']);
 DB::table('firebase_documents')->insert(['collection_path'=>'posts','document_id'=>'legacy-post','payload'=>json_encode(['title'=>'Public post','content'=>'<div>Paragraph one</div><p>Paragraph two</p>','media'=>[['url'=>'https://example.test/image.jpg','type'=>'image']],'authorEmail'=>'private@example.test'])]);
 }
 public function test_legacy_and_numeric_links_resolve_same_canonical_post_without_private_profile_fields(): void {
  foreach(['123','legacy-post'] as $id)$this->getJson('/api/v1/public/posts/'.$id)->assertOk()->assertJsonPath('data.id','legacy-post')->assertJsonPath('data.content','<div>Paragraph one</div><p>Paragraph two</p>')->assertJsonMissingPath('data.authorEmail');
 }
 public function test_private_and_draft_posts_never_become_public_previews(): void {
  DB::table('posts')->update(['audience'=>'private']);$this->getJson('/api/v1/public/posts/legacy-post')->assertNotFound();
  DB::table('posts')->update(['audience'=>'public']);DB::table('firebase_documents')->update(['payload'=>'{"status":"draft"}']);$this->getJson('/api/v1/public/posts/legacy-post')->assertNotFound();
 }
 public function test_reel_preview_and_sitemap_exclude_private_deleted_and_draft_content(): void {
  foreach(['public'=>[], 'private'=>['audience'=>'friends'], 'draft'=>['status'=>'draft'], 'deleted'=>['deletedAt'=>123]] as $id=>$extra)DB::table('firebase_documents')->insert(['collection_path'=>'reels','document_id'=>$id,'payload'=>json_encode(array_merge(['moderationStatus'=>'pending','status'=>'published','videoUrl'=>'https://example.test/video.mp4','thumbnailUrl'=>'https://example.test/poster.jpg','authorEmail'=>'secret@example.test'],$extra))]);
  $this->getJson('/api/v1/public/reels/public')->assertOk()->assertJsonPath('data.type','reel')->assertJsonPath('data.media.0.type','video')->assertJsonMissingPath('data.authorEmail');
  foreach(['private','draft','deleted','missing'] as $id)$this->getJson('/api/v1/public/reels/'.$id)->assertNotFound();
  DB::table('firebase_documents')->insert(['collection_path'=>'reels','document_id'=>'metrics-only','payload'=>'{"viewCount":1}']);
  $this->getJson('/api/v1/public/reels/metrics-only')->assertNotFound();
  $this->getJson('/api/v1/public/content-index?page=1')->assertOk()->assertJsonCount(2,'items')->assertJsonPath('items.1.id','public');
  DB::table('posts')->update(['audience'=>'private']);
  $this->getJson('/api/v1/public/content-index?page=1')->assertOk()->assertJsonCount(1,'items');
 }
}
