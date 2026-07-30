<?php

namespace App\Console\Commands;

use App\Mail\TrialExpiring;
use App\Models\Payment\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckExpiringTrials extends Command
{
    protected $signature   = 'subscriptions:check-trials {--dry-run : Afficher sans envoyer}';
    protected $description = 'Vérifier les trials qui expirent bientôt et envoyer des rappels';

    public function handle(): int
    {
        // 1. Trials qui expirent dans 3 jours
        $expiringIn3Days = Subscription::where('status', 'trialing')
            ->whereBetween('trial_ends_at', [now()->addDays(2)->startOfDay(), now()->addDays(3)->endOfDay()])
            ->with(['account.owner', 'plan'])
            ->get();

        // 2. Trials qui expirent demain
        $expiringTomorrow = Subscription::where('status', 'trialing')
            ->whereBetween('trial_ends_at', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])
            ->with(['account.owner', 'plan'])
            ->get();

        // 3. Trials expirés (pas encore mis à jour)
        $expired = Subscription::where('status', 'trialing')
            ->where('trial_ends_at', '<', now())
            ->with(['account.owner'])
            ->get();

        $this->info("🔍 Résultats:");
        $this->info("  - Expire dans 3 jours : {$expiringIn3Days->count()}");
        $this->info("  - Expire demain        : {$expiringTomorrow->count()}");
        $this->info("  - Expirés              : {$expired->count()}");

        if ($this->option('dry-run')) {
            $this->warn('Mode dry-run : aucun email envoyé.');
            return self::SUCCESS;
        }

        // Envoyer les rappels J-3
        foreach ($expiringIn3Days as $subscription) {
            $user = $subscription->account?->owner;
            if ($user?->email) {
                try {
                    Mail::to($user->email)->send(new TrialExpiring($subscription->account, 3));
                    $this->line("  ✅ Rappel J-3 envoyé à {$user->email}");
                } catch (\Exception $e) {
                    $this->error("  ❌ Échec email pour {$user->email}: {$e->getMessage()}");
                }
            }
        }

        // Envoyer les rappels J-1
        foreach ($expiringTomorrow as $subscription) {
            $user = $subscription->account?->owner;
            if ($user?->email) {
                try {
                    Mail::to($user->email)->send(new TrialExpiring($subscription->account, 1));
                    $this->line("  ✅ Rappel J-1 envoyé à {$user->email}");
                } catch (\Exception $e) {
                    $this->error("  ❌ Échec email pour {$user->email}: {$e->getMessage()}");
                }
            }
        }

        // Marquer les expirés (pas de payment → garder 'trialing' mais laisser le middleware gérer)
        // On les laisse en 'trialing' avec trial_ends_at passé → le middleware les bloquera
        // Optionnel : les marquer 'canceled' si souhaité
        Log::info('CheckExpiringTrials: Run completed', [
            'expiring_3d' => $expiringIn3Days->count(),
            'expiring_1d' => $expiringTomorrow->count(),
            'expired'     => $expired->count(),
        ]);

        $this->info('✅ Commande terminée.');
        return self::SUCCESS;
    }
}
