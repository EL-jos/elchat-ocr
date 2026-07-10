<?php

namespace App\Services\Social\Email;

use App\Models\Social\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailWatchService
{
    public function register(SocialAccount $account): ?array
    {
        try {
            $response = Http::withToken($account->access_token)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/watch', [
                    'topicName'  => config('services.gmail.pubsub_topic'),
                    'labelIds'   => ['INBOX'],
                    'labelFilterBehavior' => 'include',
                ]);

            if (!$response->successful()) {
                Log::error('[Gmail] Watch registration failed', [
                    'account_id' => $account->id,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return null;
            }

            return $response->json();

        } catch (\Throwable $e) {
            Log::error('[Gmail] Watch exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function unregister(SocialAccount $account): void
    {
        try {
            Http::withToken($account->access_token)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/stop');
        } catch (\Throwable $e) {
            Log::warning('[Gmail] Watch unregister failed', ['error' => $e->getMessage()]);
        }
    }

    public function renew(SocialAccount $account): bool
    {
        $result = $this->register($account);

        if ($result) {
            $account->update([
                'sync_cursor'        => $result['historyId'],
                'webhook_expires_at' => now()->addDays(7),
            ]);
            return true;
        }

        return false;
    }
}
