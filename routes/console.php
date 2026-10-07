<?php

use App\Jobs\Proactive\SendProactiveMessageJob;
use App\Jobs\RunProspectingCampaignJob;
use App\Jobs\SyncProspectToCrmJob;
use App\Jobs\Microsoft365SyncJob;
use App\Jobs\WebsiteGrowthAdvisor\RunWebsiteGrowthAdvisorAnalysisJob;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Site;
use App\Models\WebsiteGrowthAdvisorAnalysis;
use App\Models\WebsiteGrowthAdvisorConfiguration;
use App\Models\Social\SocialAnalysis;
use App\Models\Social\SocialIntelligenceSetting;
use App\Jobs\SocialIntelligence\{RunSocialIntelligenceAnalysisJob, SyncSocialIntelligenceJob};
use App\SocialIntelligence\Services\SocialIntelligenceConfigurationService;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectingCampaign;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorConfigurationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

Schedule::command('social-intelligence:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(900)
    ->onOneServer()
    ->runInBackground();

Schedule::call(function () {
    SocialIntelligenceSetting::query()
        ->where('is_active', true)
        ->whereIn('frequency', ['daily', 'weekly', 'monthly'])
        ->whereNotNull('next_run_at')
        ->where('next_run_at', '<=', now())
        ->with('site')
        ->each(function (SocialIntelligenceSetting $setting) {
            $configurationService = app(SocialIntelligenceConfigurationService::class);
            $configuration = $configurationService->normalize($setting->site, $setting->toArray());
            $period = $configurationService->period($configuration);
            $analysis = SocialAnalysis::create([
                'account_id' => $setting->account_id, 'site_id' => $setting->site_id,
                'period_from' => $period['from'], 'period_to' => $period['to'],
                'comparison_from' => $period['comparisonFrom'], 'comparison_to' => $period['comparisonTo'],
                'configuration' => $configuration, 'depth' => $configuration['depth'],
                'status' => 'queued', 'phase' => 'queued',
            ]);
            $setting->update(['next_run_at' => $configurationService->nextRunAt($configuration, now())]);
            Bus::chain([
                new SyncSocialIntelligenceJob((string) $setting->site_id, $configuration['sources']),
                new RunSocialIntelligenceAnalysisJob((string) $analysis->id),
            ])->onQueue(config('social-intelligence.queue', 'analytics'))->dispatch();
        });
})->everyFifteenMinutes()->name('social-intelligence-analysis-scheduler')->withoutOverlapping(900)->onOneServer();

Schedule::command('subscriptions:finalize-cancellations')->dailyAt('02:00');
Schedule::command('subscriptions:check-expired-trials')->dailyAt('08:00');

Schedule::command('analytics:aggregate --days=2')
    ->hourlyAt(10)
    ->withoutOverlapping(55)
    ->onOneServer();
Schedule::command('analytics:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// Delta sync Microsoft 365 : le curseur Graph est conservé par drive/site,
// les fichiers sont téléchargés uniquement vers le stockage privé puis
// réindexés par la file documentaire existante.
Schedule::call(function () {
    McpSiteConnector::query()
        ->where('status', 'connected')
        ->whereHas('mcpConnector', fn ($q) => $q->where('slug', 'microsoft_365'))
        ->pluck('site_id')
        ->each(fn (string $siteId) => Microsoft365SyncJob::dispatch(Site::find($siteId)));
})->everyFifteenMinutes()->name('microsoft365-delta-sync')->withoutOverlapping(900)->onOneServer();
Schedule::command('visitor-intelligence:prune')
    // Run hourly so the 48-hour replay retention is not extended by another
    // full day waiting for the nightly scheduler.
    ->hourlyAt(45)
    ->withoutOverlapping(120)
    ->onOneServer();

// 🆕 Sales Hunter — vérifie chaque minute les campagnes dont l'heure est
// arrivée. Léger (une requête indexée sur next_run_at), même principe
// que tout job planifié existant — driver `database`, workers Supervisor.
Schedule::call(function () {
    ProspectingCampaign::where('next_run_at', '<=', now())
        ->whereIn('status', ['scheduled', 'completed', 'failed'])
        ->pluck('id')
        ->each(function (string $campaignId) {
            $queued = DB::transaction(function () use ($campaignId) {
                $campaign = ProspectingCampaign::whereKey($campaignId)->lockForUpdate()->first();
                if (! $campaign || ! in_array($campaign->status, ['scheduled', 'completed', 'failed'], true) || ! $campaign->next_run_at?->isPast()) {
                    return null;
                }

                $frequency = $campaign->schedule_snapshot['frequency'] ?? 'manual';
                $time = $campaign->schedule_snapshot['time'] ?? '09:00';
                $next = match ($frequency) {
                    'daily' => now()->addDay()->setTimeFromTimeString($time),
                    'every_2_days' => now()->addDays(2)->setTimeFromTimeString($time),
                    'weekly' => now()->addWeek()->setTimeFromTimeString($time),
                    default => null,
                };
                $campaign->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'completed_at' => null,
                    'next_run_at' => $next,
                    'stats' => array_merge($campaign->stats ?? [], [
                        'last_run_trigger' => 'scheduled',
                        'last_run_requested_at' => now()->toIso8601String(),
                    ]),
                ]);

                return $campaign->id;
            });

            if (! $queued) {
                return;
            }

            try {
                RunProspectingCampaignJob::dispatch($queued, 'scheduled');
            } catch (Throwable $exception) {
                ProspectingCampaign::whereKey($queued)->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'stats' => array_merge(ProspectingCampaign::find($queued)?->stats ?? [], ['last_error' => $exception->getMessage()]),
                ]);
            }
        });
})->everyMinute()->name('sales-hunter-campaign-scheduler')->withoutOverlapping()->onOneServer();

// Website Growth Advisor — la planification réutilise le scheduler Laravel
// existant et le même job dédié. Le verrou DB évite qu'un même tenant crée
// deux analyses si plusieurs instances du scheduler se réveillent ensemble.
Schedule::call(function () {
    $configurations = WebsiteGrowthAdvisorConfiguration::query()
        ->where('is_active', true)
        ->where('execution_mode', 'scheduled')
        ->whereNotNull('next_run_at')
        ->where('next_run_at', '<=', now())
        ->pluck('id');

    foreach ($configurations as $configurationId) {
        $analysisId = DB::transaction(function () use ($configurationId): ?string {
            $configuration = WebsiteGrowthAdvisorConfiguration::query()
                ->whereKey($configurationId)
                ->lockForUpdate()
                ->first();
            if (! $configuration || ! $configuration->is_active || $configuration->execution_mode !== 'scheduled' || ! $configuration->next_run_at?->isPast()) {
                return null;
            }

            $running = WebsiteGrowthAdvisorAnalysis::query()
                ->where('site_id', $configuration->site_id)
                ->whereIn('status', ['queued', 'running'])
                ->exists();
            if ($running) {
                return null;
            }

            $settings = app(WebsiteGrowthAdvisorConfigurationService::class)->effective($configuration);
            $period = app(WebsiteGrowthAdvisorConfigurationService::class)->resolvePeriod($settings);
            $analysis = WebsiteGrowthAdvisorAnalysis::query()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'account_id' => $configuration->account_id,
                'site_id' => $configuration->site_id,
                'agent_id' => $configuration->agent_id,
                'period_from' => $period['from'],
                'period_to' => $period['to'],
                'include_external' => true,
                'configuration_snapshot' => array_merge($settings, [
                    'configuration_id' => (string) $configuration->id,
                    'configuration_version' => (int) $configuration->version,
                    'run_include_external' => true,
                    'trigger' => 'scheduled',
                ]),
                'status' => 'queued',
                'progress' => 0,
                'phase' => 'queued',
                'progress_message' => 'Analyse planifiée placée dans la file d’attente.',
                'source_status' => [],
                'representative_sessions_count' => 0,
                'visual_moments_count' => 0,
            ]);
            $configuration->next_run_at = app(WebsiteGrowthAdvisorConfigurationService::class)->scheduleNextRun($settings);
            $configuration->save();

            return (string) $analysis->id;
        });

        if ($analysisId) {
            RunWebsiteGrowthAdvisorAnalysisJob::dispatch($analysisId);
        }
    }
})->everyMinute()->name('website-growth-advisor-scheduler')->withoutOverlapping()->onOneServer();

// Reprend les prospects conservés localement lorsqu'un CRM était absent,
// temporairement indisponible ou lorsqu'une adresse email a été complétée.
Schedule::call(function () {
    Prospect::query()
        ->whereIn('crm_sync_status', ['pending_crm', 'pending_email', 'failed'])
        ->whereIn('status', ['qualified', 'discovered'])
        ->orderBy('updated_at')
        ->limit(200)
        ->pluck('id')
        ->each(fn (string $id) => SyncProspectToCrmJob::dispatch($id));
})->everyTenMinutes()->name('sales-hunter-crm-sync')->withoutOverlapping()->onOneServer();

Schedule::call(function () {
    // Un worker peut être interrompu après le verrouillage DB et avant la
    // remise à jour du message. Les verrous expirés sont remis en retry afin
    // d'éviter une séquence bloquée définitivement.
    ProactiveMessage::query()
        ->where('status', 'processing')
        ->whereNotNull('locked_at')
        ->where('locked_at', '<=', now()->subMinutes((int) config('proactive.stale_lock_minutes', 15)))
        ->update([
            'status' => 'retrying',
            'locked_at' => null,
            'failure_code' => 'stale_lock_recovered',
            'failure_details' => 'Le verrou de traitement a expiré avant la confirmation du canal.',
        ]);

    ProactiveMessage::query()
        ->whereIn('status', ['scheduled', 'retrying'])
        ->where('scheduled_at', '<=', now())
        ->orderBy('scheduled_at')
        ->limit((int) config('proactive.scan_batch_size', 200))
        ->pluck('id')
        ->each(fn (string $id) => SendProactiveMessageJob::dispatch($id)->onQueue(config('proactive.queue', 'proactive')));
})->everyMinute()->name('proactive-engagement-scheduler')->withoutOverlapping()->onOneServer();
