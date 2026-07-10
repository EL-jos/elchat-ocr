<?php

namespace App\Services\Social\Email;

use App\Models\Social\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OutlookSubscriptionService
{
    private string $graphUrl = 'https://graph.microsoft.com/v1.0';

    public function register(SocialAccount $account): ?array
    {
        try {
            $response = Http::withToken($account->access_token)
                ->post("{$this->graphUrl}/subscriptions", [
                    'changeType'         => 'created',
                    'notificationUrl'    => route('webhook.email.outlook'),
                    'resource'           => 'me/mailFolders/inbox/messages',
                    'expirationDateTime' => now()->addMinutes(4230)->toIso8601String(),
                    'clientState'        => $this->buildClientState($account),
                ]);

            if (!$response->successful()) {
                Log::error('[Outlook] Subscription registration failed', [
                    'account_id' => $account->id,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return null;
            }

            return $response->json();

        } catch (\Throwable $e) {
            Log::error('[Outlook] Subscription exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function unregister(SocialAccount $account): void
    {
        $subscriptionId = $account->metadata['subscription_id'] ?? null;

        if (!$subscriptionId) return;

        try {
            Http::withToken($account->access_token)
                ->delete("{$this->graphUrl}/subscriptions/{$subscriptionId}");
        } catch (\Throwable $e) {
            Log::warning('[Outlook] Subscription unregister failed', ['error' => $e->getMessage()]);
        }
    }

    public function renew(SocialAccount $account): bool
    {
        $subscriptionId = $account->metadata['subscription_id'] ?? null;

        if (!$subscriptionId) {
            $result = $this->register($account);
            return $result !== null;
        }

        try {
            $response = Http::withToken($account->access_token)
                ->patch("{$this->graphUrl}/subscriptions/{$subscriptionId}", [
                    'expirationDateTime' => now()->addMinutes(4230)->toIso8601String(),
                ]);

            if ($response->successful()) {
                $account->update([
                    'webhook_expires_at' => now()->addMinutes(4230),
                ]);
                return true;
            }

        } catch (\Throwable $e) {
            Log::error('[Outlook] Subscription renew failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    public function buildClientState(SocialAccount $account): string
    {
        return hash('sha256', $account->id . config('app.key'));
    }
}
