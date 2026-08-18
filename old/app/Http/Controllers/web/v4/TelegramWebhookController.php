<?php

namespace App\Http\Controllers\web\v4;

use App\Enums\Social\SocialProvider;
use App\Http\Controllers\Controller;
use App\Jobs\social\TelegramWebhookJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
class TelegramWebhookController extends Controller
{
    /**
     * Telegram envoie les updates sur POST /webhook/telegram/{accountId}
     *
     * Sécurité : double vérification
     * 1. {accountId} dans l'URL → identifie le bon SocialAccount
     * 2. X-Telegram-Bot-Api-Secret-Token → vérifie que c'est bien Telegram
     */
    public function handle(Request $request, string $accountId)
    {

        Log::info('TELEGRAM WEBHOOK HIT', [
            'accountId' => $accountId,
            'payload' => $request->getContent(),
            'request' => $request->all(),
        ]);
        // ✅ Identifier le compte immédiatement via l'URL
        $account = SocialAccount::where('id', $accountId)
            ->where('provider', SocialProvider::TELEGRAM->value)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            // ✅ Répondre 200 pour éviter que Telegram ne retente
            Log::warning('[Telegram][Webhook] SocialAccount introuvable ou inactif', [
                'account_id' => $accountId,
            ]);
            return response()->json(['ok' => true]);
        }

        // ✅ Vérifier le secret token (header envoyé par Telegram)
        $receivedSecret  = $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expectedSecret  = TelegramConnectController::buildSecretToken($account->access_token);

        if (!hash_equals($expectedSecret, $receivedSecret ?? '')) {
            Log::warning('[Telegram][Webhook] Secret token invalide', [
                'account_id' => $accountId,
                'ip'         => $request->ip(),
            ]);
            // ✅ 200 quand même — on ne veut pas que Telegram retente
            return response()->json(['ok' => true]);
        }

        $rawPayload = $request->getContent();

        try {

            $body = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);

            // ✅ Déduplication via update_id (Telegram garantit l'unicité par bot)
            $updateId = (string) ($body['update_id'] ?? null);

            if ($updateId) {
                $alreadyProcessed = SocialEvent::where('provider', SocialProvider::TELEGRAM->value)
                    ->where('external_event_id', $updateId)
                    ->where('social_account_id', $account->id)
                    ->exists();

                if ($alreadyProcessed) {
                    Log::info('[Telegram][Webhook] Update déjà traité (dédup)', [
                        'update_id'  => $updateId,
                        'account_id' => $accountId,
                    ]);
                    return response()->json(['ok' => true]);
                }
            }

            $event = SocialEvent::create([
                'social_account_id' => $account->id,
                'provider'          => SocialProvider::TELEGRAM->value,
                'event_type'        => $this->resolveEventType($body),
                'external_event_id' => $updateId,
                'payload'           => $body,
                'processing_status' => 'pending',
                'metadata' => [
                    'received_at'  => now()->toISOString(),
                    'ip'           => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                    'payload_hash' => hash('sha256', $rawPayload),
                ],
            ]);

            TelegramWebhookJob::dispatch($event->id);

            Log::info('[Telegram][Webhook] Event créé', [
                'event_id'   => $event->id,
                'update_id'  => $updateId,
                'event_type' => $event->event_type,
                'account_id' => $accountId,
            ]);

        } catch (Throwable $e) {

            report($e);
            // ✅ 200 quand même — Telegram ne doit pas retenter
        }

        // ✅ Telegram attend toujours {"ok": true}
        return response()->json(['ok' => true]);
    }

    private function resolveEventType(array $body): string
    {
        return match (true) {
            isset($body['message'])         => 'message',
            isset($body['edited_message'])  => 'edited_message',
            isset($body['channel_post'])    => 'channel_post',
            isset($body['callback_query'])  => 'callback_query',
            default                         => 'unknown',
        };
    }
}
