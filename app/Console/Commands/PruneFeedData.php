<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneFeedData extends Command
{
    protected $signature='feed:prune';
    protected $description='Remove expired feed cursors and interaction events older than 30 days';
    public function handle(): int
    {
        $cursors=DB::table('feed_cursors')->where('expires_at','<',now()->toIso8601String())->delete();
        $events=DB::table('feed_interactions')->where('created_at','<',now()->subDays(30)->toIso8601String())->delete();
        $this->info("Pruned {$cursors} cursors and {$events} interaction events.");
        return self::SUCCESS;
    }
}
