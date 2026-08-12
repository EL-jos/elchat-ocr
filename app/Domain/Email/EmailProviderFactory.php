<?php

namespace App\Domain\Email;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\Providers\{MailtrapEmailProvider, PostmarkEmailProvider, SesEmailProvider};
use Aws\Ses\SesClient;

/**
 * SEUL endroit de l'application qui sait construire un provider concret à
 * partir de sa clé — utilisé par le binding EmailProviderInterface ET par
 * la commande `email:provider:status` (voir EmailProviderStatusCommand),
 * pour que les deux restent toujours cohérents entre eux.
 *
 * Basculer de fournisseur = changer EMAIL_PROVIDER dans .env + vider le
 * cache de config. Rien d'autre à toucher, nulle part.
 */
class EmailProviderFactory
{
    /** Déclare TOUS les fournisseurs connus, même ceux pas encore actifs — sert au diagnostic. */
    public const KNOWN_PROVIDERS = ['ses', 'postmark', 'mailtrap'];

    public static function make(string $key): EmailProviderInterface
    {
        return match ($key) {
            'ses' => new SesEmailProvider(app(SesClient::class)),
            'postmark' => new PostmarkEmailProvider(
                serverToken: (string) env('POSTMARK_SERVER_TOKEN'),
                webhookUsername: (string) env('POSTMARK_WEBHOOK_USERNAME'),
                webhookPassword: (string) env('POSTMARK_WEBHOOK_PASSWORD'),
            ),
            'mailtrap' => new MailtrapEmailProvider(
                apiToken: (string) env('MAILTRAP_API_TOKEN'),
                webhookSigningSecret: (string) env('MAILTRAP_WEBHOOK_SECRET'),
            ),
            default => throw new \InvalidArgumentException("Fournisseur email '{$key}' inconnu. Valeurs possibles : " . implode(', ', self::KNOWN_PROVIDERS)),
        };
    }

    /** Variables d'environnement requises pour qu'un fournisseur soit réellement utilisable. */
    public static function requiredEnvKeys(string $key): array
    {
        return match ($key) {
            'ses' => ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SES_REGION'],
            'postmark' => ['POSTMARK_SERVER_TOKEN', 'POSTMARK_WEBHOOK_USERNAME', 'POSTMARK_WEBHOOK_PASSWORD'],
            'mailtrap' => ['MAILTRAP_API_TOKEN', 'MAILTRAP_WEBHOOK_SECRET'],
            default => [],
        };
    }

    /** true si toutes les variables requises sont présentes ET non vides. */
    public static function isConfigured(string $key): bool
    {
        foreach (self::requiredEnvKeys($key) as $envKey) {
            if (empty(env($envKey))) {
                return false;
            }
        }
        return true;
    }
}
