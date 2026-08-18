<?php

namespace App\Http\Controllers\web\v4;

use App\Enums\Social\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramConnectController extends Controller
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.telegram.bot_api', 'https://api.telegram.org');
    }

    /**
     * STEP 1 — Valider le token bot + enregistrer le webhook Telegram
     *
     * Remplace le flow OAuth : le client colle son BOT TOKEN,
     * ELChat valide via getMe(), puis enregistre le webhook
     * via setWebhook() avec une URL unique par SocialAccount.
     */
    public function connect(Request $request, string $siteId)
    {

        $request->validate([
            'bot_token' => ['required', 'string', 'min:40'],
        ]);

        $ownerId = $request->owner;
        $owner = User::findOrFail($ownerId);

        if (!$owner->ownedAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte associé à cet utilisateur.',
            ], 403);
        }

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $botToken = trim($request->bot_token);

        // ✅ Étape 1 — Valider le token via getMe()
        $botInfo = $this->getBotInfo($botToken);

        if (!$botInfo) {
            return response()->json([
                'success' => false,
                'message' => 'Token invalide. Vérifiez votre token BotFather.',
            ], 422);
        }

        $botId       = (string) $botInfo['id'];
        $botUsername = $botInfo['username'] ?? null;
        $botName     = $botInfo['first_name'] ?? $botUsername ?? 'Telegram Bot';

        // ✅ Étape 2 — Créer/récupérer le SocialAccount (on a besoin de l'ID pour l'URL)
        $socialAccount = SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => SocialProvider::TELEGRAM->value,
                'provider_account_id' => $botId,
            ],
            [
                'account_name'  => $botName,
                'access_token'  => $botToken, // ✅ le bot token EST le credential
                'refresh_token' => null,
                'metadata' => [
                    'bot_id'       => $botId,
                    'bot_username' => $botUsername,
                    'bot_name'     => $botName,
                    'can_join_groups'        => $botInfo['can_join_groups']        ?? false,
                    'can_read_all_group_messages' => $botInfo['can_read_all_group_messages'] ?? false,
                    'webhook_set'  => false, // sera mis à true après setWebhook
                    'webhook_url'  => null,
                ],
                'is_active' => true,
            ]
        );

        // ✅ Étape 3 — Enregistrer le webhook avec l'URL unique (accountId dans l'URL)
        $webhookUrl = route('webhook.telegram', ['accountId' => $socialAccount->id]);

        $webhookSet = $this->setWebhook($botToken, $webhookUrl);

        if (!$webhookSet) {
            // Rollback : désactiver le compte si le webhook échoue
            $socialAccount->update(['is_active' => false]);

            return response()->json([
                'success' => false,
                'message' => 'Bot validé mais impossible d\'enregistrer le webhook Telegram.',
            ], 502);
        }

        // ✅ Mettre à jour le metadata avec le statut du webhook
        $socialAccount->update([
            'metadata' => array_merge($socialAccount->metadata ?? [], [
                'webhook_set'       => true,
                'webhook_url'       => $webhookUrl,
                'webhook_set_at'    => now()->toIso8601String(),
            ]),
        ]);

        Log::info('[Telegram] Bot connecté avec succès', [
            'account_id'  => $socialAccount->id,
            'bot_id'      => $botId,
            'bot_username'=> $botUsername,
            'webhook_url' => $webhookUrl,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bot Telegram connecté avec succès.',
            'bot' => [
                'id'       => $botId,
                'username' => $botUsername,
                'name'     => $botName,
            ],
        ]);
    }

    /**
     * Déconnecter un bot Telegram
     * Supprime le webhook Telegram + supprime le SocialAccount
     */
    public function disconnect(Request $request, string $siteId): \Illuminate\Http\JsonResponse
    {
        $owner = User::findOrFail($request->owner);

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        /** @var SocialAccount $socialAccount */
        $socialAccount = SocialAccount::where('site_id', $site->id)
            ->where('provider', SocialProvider::TELEGRAM->value)
            ->where('is_active', true)
            ->firstOrFail();

        try {
            // 1️⃣ Supprimer le webhook côté Telegram (best effort)
            if (!empty($socialAccount->access_token)) {
                $this->deleteWebhook($socialAccount->access_token);
            }

            // 2️⃣ Supprimer proprement en base
            $accountId   = $socialAccount->id;
            $botUsername = $socialAccount->metadata['bot_username'] ?? null;

            $socialAccount->delete();

            Log::info('[Telegram] Bot déconnecté', [
                'site_id'      => $site->id,
                'account_id'   => $accountId,
                'bot_username' => $botUsername,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bot Telegram déconnecté avec succès.',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion Telegram.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────
    // TELEGRAM API HELPERS
    // ─────────────────────────────────────────────────────────

    private function getBotInfo(string $botToken): ?array
    {
        try {
            $response = Http::timeout(10)
                ->get("{$this->baseUrl}/bot{$botToken}/getMe");

            if (!$response->successful() || !$response->json('ok')) {
                Log::warning('[Telegram] getMe() échoué', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json('result');

        } catch (Throwable $e) {
            Log::error('[Telegram] Erreur getMe()', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function setWebhook(string $botToken, string $webhookUrl): bool
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->baseUrl}/bot{$botToken}/setWebhook", [
                    'url'                  => $webhookUrl,
                    'allowed_updates'      => ['message', 'edited_message', 'channel_post', 'callback_query'],
                    'drop_pending_updates' => true, // ✅ évite de traiter des messages anciens au premier connect
                    'secret_token'         => $this->buildSecretToken($botToken), // ✅ sécurité supplémentaire
                ]);

            $ok = $response->successful() && $response->json('ok') === true;

            if (!$ok) {
                Log::error('[Telegram] setWebhook() échoué', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }

            return $ok;

        } catch (Throwable $e) {
            Log::error('[Telegram] Erreur setWebhook()', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function deleteWebhook(string $botToken): void
    {
        try {
            Http::timeout(10)
                ->post("{$this->baseUrl}/bot{$botToken}/deleteWebhook", [
                    'drop_pending_updates' => true,
                ]);
        } catch (Throwable $e) {
            Log::warning('[Telegram] Erreur deleteWebhook()', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Génère un secret token déterministe à partir du bot token.
     * Utilisé pour vérifier que les requêtes viennent bien de Telegram.
     * Telegram envoie ce token dans le header X-Telegram-Bot-Api-Secret-Token.
     */
    public static function buildSecretToken(string $botToken): string
    {
        return hash('sha256', $botToken . config('app.key'));
    }
}
