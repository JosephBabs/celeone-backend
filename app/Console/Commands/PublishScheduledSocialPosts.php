<?php

namespace App\Console\Commands;

use App\Services\ScheduledSocialPosts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishScheduledSocialPosts extends Command
{
    protected $signature = 'social:publish-scheduled {--limit=100}';

    protected $description = 'Publish due social posts independently of mobile clients';

    public function handle(ScheduledSocialPosts $posts): int
    {
        $ids = DB::table('scheduled_social_posts')->where('status', 'scheduled')->where('scheduled_at', '<=', now()->toISOString())
            ->orderBy('scheduled_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        $failed = false;
        foreach ($ids as $id) {
            try {
                $posts->publish($id);
            } catch (\Throwable $error) {
                report($error);
                $failed = true;
                $this->error('Could not publish scheduled post '.$id.'; it will be retried.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
