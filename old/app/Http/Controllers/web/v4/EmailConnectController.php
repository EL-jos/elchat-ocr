<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialAuthSession;
use App\Models\User;
use App\Services\Social\Email\GmailWatchService;
use App\Services\Social\Email\OutlookSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

class EmailConnectController extends Controller
{
    public function __construct(
        private GmailWatchService           $gmailWatch,
        private OutlookSubscriptionService  $outlookSubscription,
    ) {}

    // ─────────────────────────────────────────────────────────
    // REDIRECT — Gmail + Outlook (OAuth2)
    // ─────────────────────────────────────────────────────────

    public function redirect(Request $request, string $siteId)
    {
        $provider = $request->query('provider');

        if (!in_array($provider, ['gmail', 'outlook'])) {
            return response()->json([
                'success' => false,
                'message' => 'Provider invalide. Utilisez gmail ou outlook.',
            ], 422);
        }

        $ownerId = $request->owner;
        $owner = User::findOrFail($ownerId);

        if (!$owner->ownedAccount) {
            return $this->popupResponse(false, 'Aucun compte associé.');
        }

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $auth = SocialAuthSession::create([
            'site_id'    => $site->id,
            'account_id' => $owner->ownedAccount->id,
            'provider'   => $provider,
        ]);

        return match ($provider) {
            'gmail'   => $this->redirectGmail($auth->id),
            'outlook' => $this->redirectOutlook($auth->id),
        };
    }

    private function redirectGmail(string $authId)
    {
        return Socialite::driver('google')
            ->redirectUrl(config('services.gmail.redirect')) // ✅ forcé
            ->scopes([
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/gmail.modify',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt'      => 'consent',
                'state'       => $authId,
            ])
            ->redirect();
    }

    private function redirectOutlook(string $authId)
    {
        return Socialite::driver('microsoft')
            ->scopes([
                'openid',
                'email',
                'profile',
                'offline_access',
                'Mail.Read',
                'Mail.Send',
                'Mail.ReadWrite',
            ])
            ->with(['state' => $authId])
            ->redirect();
    }

    // ─────────────────────────────────────────────────────────
    // CALLBACK GMAIL
    // ─────────────────────────────────────────────────────────

    public function callbackGmail(Request $request)
    {
        $authId = $request->state;

        Log::info("DANS CALLBACK GMAIL", [
            'authId' => $authId,
            'request' => $request->all(),
        ]);

        if (!$authId) {
            Log::info("Session expirée.");
            return $this->popupResponse(false, 'Session expirée.');
        }

        $auth = SocialAuthSession::where('id', $authId)
            ->where('provider', 'gmail')
            ->first();

        if (!$auth) {
            Log::info("Session expirée. Reconnectez Gmail.");
            return $this->popupResponse(false, 'Session expirée. Reconnectez Gmail.');
        }

        try {

            $googleUser   = Socialite::driver('google')
                ->redirectUrl(config('services.gmail.redirect'))
                ->stateless()->user();
            $accessToken  = $googleUser->token;
            $refreshToken = $googleUser->refreshToken;
            $expiresIn    = $googleUser->expiresIn;

            // ✅ Récupérer le profil Gmail (adresse email)
            $email = $googleUser->email;
            $name  = $googleUser->name;

            // ✅ Stocker dans payload pour le storeAccount
            $finalRefreshToken = $refreshToken ?: $auth->refresh_token;

            $auth->update([
                'access_token' => $accessToken,
                'expires_at'   => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'payload'      => [
                    'email'         => $email,
                    'name'          => $name,
                    'refresh_token' => $finalRefreshToken,
                    'google_id'     => $googleUser->id,
                ],
            ]);

            return $this->popupResponse(
                true,
                'Gmail connecté avec succès',
                [
                    'auth_id'  => $auth->id,
                    'provider' => 'gmail',
                    'email'    => $email,
                    'name'     => $name,
                ]
            );

        } catch (Throwable $e) {

            Log::info("Erreur lors de la connexion Gmail.");
            report($e);
            return $this->popupResponse(false, 'Erreur lors de la connexion Gmail.');
        }
    }

    // ─────────────────────────────────────────────────────────
    // CALLBACK OUTLOOK
    // ─────────────────────────────────────────────────────────

    public function callbackOutlook(Request $request)
    {
        $authId = $request->state;

        if (!$authId) {
            return $this->popupResponse(false, 'Session expirée.');
        }

        $auth = SocialAuthSession::where('id', $authId)
            ->where('provider', 'outlook')
            ->first();

        if (!$auth) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez Outlook.');
        }

        try {

            $msUser       = Socialite::driver('microsoft')->stateless()->user();
            $accessToken  = $msUser->token;
            $refreshToken = $msUser->refreshToken;
            $expiresIn    = $msUser->expiresIn;

            $email = $msUser->email;
            $name  = $msUser->name;

            $finalRefreshToken = $refreshToken ?: $auth->refresh_token;

            $auth->update([
                'access_token' => $accessToken,
                'expires_at'   => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'payload'      => [
                    'email'         => $email,
                    'name'          => $name,
                    'refresh_token' => $finalRefreshToken,
                    'ms_id'         => $msUser->id,
                ],
            ]);

            return $this->popupResponse(
                true,
                'Outlook connecté avec succès',
                [
                    'auth_id'  => $auth->id,
                    'provider' => 'outlook',
                    'email'    => $email,
                    'name'     => $name,
                ]
            );

        } catch (Throwable $e) {
            report($e);
            return $this->popupResponse(false, 'Erreur lors de la connexion Outlook.');
        }
    }

    // ─────────────────────────────────────────────────────────
    // CONNECT — store account après OAuth (Gmail + Outlook)
    //           + connexion directe IMAP
    // ─────────────────────────────────────────────────────────

    public function connect(Request $request, string $siteId)
    {
        $provider = $request->input('provider');

        return match ($provider) {
            'gmail'   => $this->storeGmail($request, $siteId),
            'outlook' => $this->storeOutlook($request, $siteId),
            'imap'    => $this->storeImap($request, $siteId),
            default   => response()->json([
                'success' => false,
                'message' => 'Provider invalide.',
            ], 422),
        };
    }

    private function storeGmail(Request $request, string $siteId): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'auth_id' => ['required', 'uuid'],
        ]);

        $auth = SocialAuthSession::where('id', $request->auth_id)
            ->where('provider', 'gmail')
            ->firstOrFail();

        $site = Site::where('id', $siteId)
            ->where('account_id', $auth->account_id)
            ->firstOrFail();

        $payload      = $auth->payload ?? [];
        $refreshToken = $payload['refresh_token'] ?? null;

        if (!$refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Token manquant. Reconnectez votre compte Gmail.',
            ], 422);
        }

        $socialAccount = SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => 'gmail',
                'provider_account_id' => $payload['google_id'] ?? $payload['email'],
            ],
            [
                'account_name'     => $payload['name']  ?? $payload['email'],
                'access_token'     => $auth->access_token ?? 'gmail',
                'refresh_token'    => $refreshToken,
                'token_expires_at' => $auth->expires_at,
                'metadata' => [
                    'email'        => $payload['email'],
                    'name'         => $payload['name'] ?? null,
                    'provider'     => 'gmail',
                ],
                'is_active' => true,
            ]
        );

        // ✅ Enregistrer Gmail Watch (Pub/Sub) pour recevoir les nouveaux emails
        $watchResult = $this->gmailWatch->register($socialAccount);

        if ($watchResult) {
            $initialHistoryId = (int) $watchResult['historyId'];

            $socialAccount->update([
                // ✅ Stocker historyId - 1 pour que le PREMIER email reçu soit inclus
                // Gmail history.list(startHistoryId=X) retourne events avec historyId > X
                // Donc si on stocke X-1, le premier event avec historyId=X sera inclus
                'sync_cursor'        => (string) max(1, $initialHistoryId - 1),
                'webhook_expires_at' => now()->addDays(7),
                'metadata'           => array_merge($socialAccount->metadata ?? [], [
                    'watch_expiration' => $watchResult['expiration'],
                    'pubsub_topic'     => config('services.gmail.pubsub_topic'),
                ]),
            ]);
        }

        $auth->delete();

        Log::info('[Gmail] Compte connecté', [
            'account_id' => $socialAccount->id,
            'email'      => $payload['email'],
            'watch'      => $watchResult ? 'ok' : 'failed',
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Gmail connecté avec succès.',
            'account'  => [
                'email' => $payload['email'],
                'name'  => $payload['name'] ?? null,
            ],
        ]);
    }

    private function storeOutlook(Request $request, string $siteId): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'auth_id' => ['required', 'uuid'],
        ]);

        $auth = SocialAuthSession::where('id', $request->auth_id)
            ->where('provider', 'outlook')
            ->firstOrFail();

        $site = Site::where('id', $siteId)
            ->where('account_id', $auth->account_id)
            ->firstOrFail();

        $payload      = $auth->payload ?? [];
        $refreshToken = $payload['refresh_token'] ?? null;

        if (!$refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Token manquant. Reconnectez votre compte Outlook.',
            ], 422);
        }

        $socialAccount = SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => 'outlook',
                'provider_account_id' => $payload['ms_id'] ?? $payload['email'],
            ],
            [
                'account_name'     => $payload['name']  ?? $payload['email'],
                'access_token'     => $auth->access_token ?? 'outlook',
                'refresh_token'    => $refreshToken,
                'token_expires_at' => $auth->expires_at,
                'metadata' => [
                    'email'    => $payload['email'],
                    'name'     => $payload['name'] ?? null,
                    'provider' => 'outlook',
                ],
                'is_active' => true,
            ]
        );

        // ✅ Créer la subscription Graph API pour recevoir les nouveaux emails
        $subscription = $this->outlookSubscription->register($socialAccount);

        if ($subscription) {
            $socialAccount->update([
                'webhook_expires_at' => now()->addMinutes(4230),
                'metadata'           => array_merge($socialAccount->metadata ?? [], [
                    'subscription_id'         => $subscription['id'],
                    'subscription_expires_at' => $subscription['expirationDateTime'],
                ]),
            ]);
        }

        $auth->delete();

        Log::info('[Outlook] Compte connecté', [
            'account_id'  => $socialAccount->id,
            'email'       => $payload['email'],
            'subscription'=> $subscription ? 'ok' : 'failed',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Outlook connecté avec succès.',
            'account' => [
                'email' => $payload['email'],
                'name'  => $payload['name'] ?? null,
            ],
        ]);
    }

    private function storeImap(Request $request, string $siteId): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'host'     => ['required', 'string'],
            'port'     => ['required', 'integer', 'min:1', 'max:65535'],
            'host_smtp'     => ['required', 'string'],
            'port_smtp'     => ['required', 'integer', 'min:1', 'max:65535'],
            'ssl'      => ['boolean'],
            'name'     => ['nullable', 'string', 'max:255'],
        ]);

        $ownerId = $request->owner;
        $owner = User::findOrFail($ownerId);

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        // ✅ Tester la connexion IMAP AVANT de stocker quoi que ce soit
        $testResult = $this->testImapConnection(
            host: $request->host,
            port: $request->port,
            email: $request->email,
            password: $request->password,
            ssl: $request->boolean('ssl', true),
        );

        if (!$testResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Connexion IMAP échouée : ' . $testResult['error'],
            ], 422);
        }

        // ✅ Chiffrer le mot de passe (AES-256-CBC via APP_KEY)
        $encryptedPassword = encrypt($request->password);

        SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => 'imap',
                'provider_account_id' => $request->email,
            ],
            [
                'account_name'  => $request->name ?? $request->email,
                'access_token'  => "imap",
                'refresh_token' => null,
                'metadata' => [
                    'email'    => $request->email,
                    'name'     => $request->name ?? null,
                    'provider' => 'imap',
                    'imap' => [
                        'host'     => $request->host,
                        'port'     => (int) $request->port,
                        'ssl'      => $request->boolean('ssl', true),
                        'password' => $encryptedPassword, // ✅ chiffré
                    ],
                    'smtp' => [
                        'host' => $request->host_smtp,
                        'port' => $request->port_smtp
                    ]
                ],
                'is_active' => true,
            ]
        );

        Log::info('[IMAP] Compte connecté', [
            'email' => $request->email,
            'host'  => $request->host,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Boîte email IMAP connectée avec succès.',
            'account' => [
                'email' => $request->email,
                'name'  => $request->name ?? null,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // DISCONNECT
    // ─────────────────────────────────────────────────────────

    /**
     * Déconnecter un compte email (gmail / outlook / imap)
     * Révoque les subscriptions/webhooks côté provider + supprime le SocialAccount
     */
    public function disconnect(Request $request, string $siteId): JsonResponse
    {
        $provider = $request->query('provider');

        if (!in_array($provider, ['gmail', 'outlook', 'imap'])) {
            return response()->json([
                'success' => false,
                'message' => 'Provider invalide. Utilisez gmail, outlook ou imap.',
            ], 422);
        }

        $owner = User::findOrFail($request->owner);

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        /** @var SocialAccount $socialAccount */
        $socialAccount = SocialAccount::where('site_id', $site->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->firstOrFail();

        try {
            // 1️⃣ Révoquer côté provider (best effort — ne bloque pas la suppression)
            match ($provider) {
                // Arrêter le push Gmail (Google Pub/Sub watch)
                'gmail' => $this->gmailWatch->unregister($socialAccount),

                // Supprimer la subscription Graph API Outlook
                'outlook' => $this->outlookSubscription->unregister($socialAccount),

                // IMAP : rien à révoquer côté provider
                'imap' => null,

                default => null,
            };

            $accountId = $socialAccount->id;
            $email     = $socialAccount->metadata['email'] ?? $socialAccount->account_name;

            // 2️⃣ Supprimer proprement en base
            $socialAccount->delete();

            Log::info('[Email] Compte déconnecté', [
                'site_id'    => $site->id,
                'account_id' => $accountId,
                'provider'   => $provider,
                'email'      => $email,
            ]);

            return response()->json([
                'success' => true,
                'message' => ucfirst($provider) . ' déconnecté avec succès.',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion ' . ucfirst($provider) . '.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    private function testImapConnection(
        string $host,
        int    $port,
        string $email,
        string $password,
        bool   $ssl,
    ): array {
        try {
            $protocol = $ssl ? 'ssl' : 'tcp';
            $socket   = @fsockopen(
                "{$protocol}://{$host}", $port, $errno, $errstr, 10
            );

            if (!$socket) {
                return ['success' => false, 'error' => "Impossible de joindre {$host}:{$port} — {$errstr}"];
            }

            fclose($socket);

            // ✅ Test IMAP complet via webklex/php-imap
            $manager = new ClientManager();
            $client  = $manager->make([
                'host'          => $host,
                'port'          => $port,
                'encryption'    => $ssl ? 'ssl' : false,
                'validate_cert' => true,
                'username'      => $email,
                'password'      => $password,
                'protocol'      => 'imap',
            ]);

            $client->connect();
            $client->disconnect();

            return ['success' => true];

        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function popupResponse(bool $ok, string $message, array $data = [])
    {
        return response()->view('social.email.popup', [
            'ok'      => $ok ? 'success' : 'error',
            'message' => $message,
            'data'    => $data,
            'origin'  => config('app.frontend_dashboard_url', 'https://elchat.io'),
        ]);
    }
}
