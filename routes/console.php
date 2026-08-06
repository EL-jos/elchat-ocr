<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// IMAP polling — toutes les 5 minutes
Schedule::command('email:sync-imap')
    ->everyFiveMinutes()
    ->withoutOverlapping(300)
    ->onOneServer()
    ->runInBackground();

// Renouvellement webhooks Gmail + Outlook — toutes les heures
Schedule::command('email:renew-webhooks')
    ->hourly()
    ->withoutOverlapping(60)
    ->onOneServer();


Schedule::command('youtube:sync-comments')
    ->everyFiveMinutes()        // ✅ pas everyMinute() — voir quota ci-dessous
    ->withoutOverlapping(600)   // verrou 10 min — couvre les syncs longs
    ->onOneServer()             // ✅ évite les doublons si plusieurs workers
    ->runInBackground();

Schedule::command('subscriptions:finalize-cancellations')->dailyAt('02:00');
Schedule::command('subscriptions:check-expired-trials')->dailyAt('08:00');