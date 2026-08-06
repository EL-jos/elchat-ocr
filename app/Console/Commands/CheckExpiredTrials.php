<?php

namespace App\Console\Commands;


use App\Models\Payment\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckExpiredTrials extends Command
{
    protected $signature   = 'subscriptions:check-expired-trials';
    protected $description = 'Détecte les trials expirés — ne convertit rien automatiquement, informe l\'utilisateur qui doit choisir ses modules à conserver';

    public function handle(): int
    {
        $expired = Subscription::where('status', 'trialing')
            ->where('trial_ends_at', '<', now())
            ->with('account.owner')
            ->get();

        foreach ($expired as $subscription) {
            // Le trial expiré bloque l'accès via le middleware applicatif
            // (redirection vers l'écran "Choisissez les modules à conserver").
            // On ne désactive PAS automatiquement ici — l'utilisateur doit agir,
            // sauf timeout supplémentaire configurable si vous le souhaitez.

            Log::info('CheckExpiredTrials: trial expired, awaiting user action', [
                'account_id' => $subscription->account_id,
            ]);

            // TODO : email de rappel (réutiliser le pattern TrialExpiring déjà conçu précédemment)
        }

        $this->info("🔍 {$expired->count()} trial(s) expiré(s) détecté(s).");

        return self::SUCCESS;
    }
}
