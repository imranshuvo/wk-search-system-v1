<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule feed sync every 5 minutes
Artisan::command('schedule:sync-feeds', function () {
    $this->info('Scheduling feeds:sync');
})->purpose('Internal');

// Define the schedule
app()->booted(function () {
    // Feed sync every 90 minutes (1.5 hours) - allows 30min buffer after WP generates feed
    // Laravel doesn't have everyNinetyMinutes(), so use cron expression: */90 * * * *
    Schedule::command('feeds:sync')->cron('*/90 * * * *');
    
    // Popular searches sync every 90 minutes (aligned with feed sync)
    Schedule::command('popular-searches:sync')->cron('*/90 * * * *');
});
