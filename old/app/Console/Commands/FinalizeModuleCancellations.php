<?php

namespace App\Console\Commands;

use App\Domain\Subscription\Services\SubscriptionOrchestrator;
use Illuminate\Console\Command;

class FinalizeModuleCancellations extends Command
{
    protected $signature   = 'subscriptions:finalize-cancellations';
    protected $description = 'Finalise les désactivations de modules dont la période payée est terminée (coupe l\'accès, jamais les données)';

    public function handle(SubscriptionOrchestrator $orchestrator): int
    {
        $count = $orchestrator->finalizeDueCancellations();

        $this->info("✅ {$count} module(s) finalisé(s) — accès révoqué, données conservées.");

        return self::SUCCESS;
    }
}
