<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('social:publish-scheduled')->everyMinute()->withoutOverlapping();

Schedule::command('feed:prune')->hourly()->withoutOverlapping();
Schedule::command('media:share-previews --limit=10')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
