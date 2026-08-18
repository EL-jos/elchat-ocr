<?php

namespace App\Console\Commands;

use App\Domain\Email\EmailProviderFactory;
use Illuminate\Console\Command;

/**
 * `php artisan email:provider:status` — vérifie en un coup d'œil quel
 * fournisseur est actif et lesquels sont réellement prêts (variables
 * d'environnement présentes), avant même d'envoyer un email de test.
 *
 * Workflow de bascule volontairement simple :
 *   1. Renseigner les variables du nouveau fournisseur dans .env
 *   2. EMAIL_PROVIDER=xxx dans .env
 *   3. php artisan config:clear
 *   4. php artisan email:provider:status → confirme que tout est vert
 */
class EmailProviderStatusCommand extends Command
{
    protected $signature = 'email:provider:status';
    protected $description = "Affiche le fournisseur email actif et l'état de configuration de chaque fournisseur disponible.";

    public function handle(): int
    {
        $active = config('mail-providers.default');

        $this->info("Fournisseur actif : {$active}");
        $this->newLine();

        $rows = [];
        foreach (EmailProviderFactory::KNOWN_PROVIDERS as $key) {
            $configured = EmailProviderFactory::isConfigured($key);
            $missing = array_filter(EmailProviderFactory::requiredEnvKeys($key), fn ($envKey) => empty(env($envKey)));

            $rows[] = [
                $key === $active ? "→ {$key}" : $key,
                $configured ? '✅ prêt' : '❌ incomplet',
                $configured ? '—' : implode(', ', $missing),
            ];
        }

        $this->table(['Fournisseur', 'Statut', 'Variables manquantes'], $rows);

        if (!EmailProviderFactory::isConfigured($active)) {
            $this->error("⚠️  Le fournisseur ACTIF ({$active}) n'est pas complètement configuré — les envois échoueront.");
            return self::FAILURE;
        }

        $this->info('Le fournisseur actif est correctement configuré.');
        return self::SUCCESS;
    }
}
