<?php

use App\Jobs\RunProspectingCampaignJob;
use App\Models\Sales\ProspectingCampaign;
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

// 🆕 Sales Hunter — vérifie chaque minute les campagnes dont l'heure est
// arrivée. Léger (une requête indexée sur next_run_at), même principe
// que tout job planifié existant — driver `database`, workers Supervisor.
Schedule::call(function () {
    ProspectingCampaign::where('next_run_at', '<=', now())
        ->whereIn('status', ['scheduled', 'completed'])
        ->get()
        ->each(function (ProspectingCampaign $campaign) {
            RunProspectingCampaignJob::dispatch($campaign->id);

            $frequency = $campaign->schedule_snapshot['frequency'] ?? 'manual';
            $time = $campaign->schedule_snapshot['time'] ?? '09:00';
            $next = match ($frequency) {
                'daily' => now()->addDay()->setTimeFromTimeString($time),
                'every_2_days' => now()->addDays(2)->setTimeFromTimeString($time),
                'weekly' => now()->addWeek()->setTimeFromTimeString($time),
                default => null, // 'manual' : ne se replanifie jamais toute seule
            };
            $campaign->update(['next_run_at' => $next]);
        });
})->everyMinute()->name('sales-hunter-campaign-scheduler')->withoutOverlapping();