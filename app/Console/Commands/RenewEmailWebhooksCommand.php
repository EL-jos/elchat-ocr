<?php

namespace App\Console\Commands;

use App\Models\Social\SocialAccount;
use App\Services\Social\Email\GmailWatchService;
use App\Services\Social\Email\OutlookSubscriptionService;
use Illuminate\Console\Command;
use Throwable;

class RenewEmailWebhooksCommand extends Command
{
    protected $signature   = 'email:renew-webhooks';
    protected $description = 'Renouvelle les webhooks Gmail (7j) et Outlook (3j) avant expiration';

    public function handle(
        GmailWatchService          $gmailWatch,
        OutlookSubscriptionService $outlookSubscription,
    ): int {

        $errors = 0;

        // ✅ Renouveler Gmail expirés dans moins de 24h
        SocialAccount::where('provider', 'gmail')
            ->where('is_active', true)
            ->where('webhook_expires_at', '<=', now()->addHours(24))
            ->each(function (SocialAccount $account) use ($gmailWatch, &$errors) {
                try {
                    $renewed = $gmailWatch->renew($account);
                    $this->info($renewed
                        ? "✓ Gmail renouvelé : {$account->account_name}"
                        : "⚠ Gmail renouvellement échoué : {$account->account_name}"
                    );
                    if (!$renewed) $errors++;
                } catch (Throwable $e) {
                    $errors++;
                    report($e);
                    $this->error("✗ Gmail {$account->account_name}: {$e->getMessage()}");
                }
            });

        // ✅ Renouveler Outlook expirés dans moins de 6h
        SocialAccount::where('provider', 'outlook')
            ->where('is_active', true)
            ->where('webhook_expires_at', '<=', now()->addHours(6))
            ->each(function (SocialAccount $account) use ($outlookSubscription, &$errors) {
                try {
                    $renewed = $outlookSubscription->renew($account);
                    $this->info($renewed
                        ? "✓ Outlook renouvelé : {$account->account_name}"
                        : "⚠ Outlook renouvellement échoué : {$account->account_name}"
                    );
                    if (!$renewed) $errors++;
                } catch (Throwable $e) {
                    $errors++;
                    report($e);
                    $this->error("✗ Outlook {$account->account_name}: {$e->getMessage()}");
                }
            });

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
