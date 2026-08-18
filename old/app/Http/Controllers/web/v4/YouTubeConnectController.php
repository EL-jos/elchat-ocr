<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialAuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class YouTubeConnectController extends Controller
{
    /**
     * STEP 1 : Redirect OAuth Google/YouTube
     */
    public function redirect(Request $request, string $siteId)
    {
        $owner = User::findOrFail($request->owner);

        if (!$owner->ownedAccount) {
            return $this->popupResponse(false, 'Aucun compte associé à cet utilisateur.');
        }

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $auth = SocialAuthSession::create([
            'site_id'    => $site->id,
            'account_id' => $owner->ownedAccount->id,
            'provider'   => 'youtube',
        ]);

        return Socialite::driver('google')
            ->scopes([
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/youtube.force-ssl',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt'      => 'consent',
                'state'       => $auth->id,
            ])
            ->redirect();
    }

    /**
     * STEP 2 : Callback OAuth Google
     */
    public function callback(Request $request)
    {
        $authId = $request->state;

        if (!$authId) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez YouTube.');
        }

        /** @var SocialAuthSession|null $auth */
        $auth = SocialAuthSession::where('id', $authId)
            ->where('provider', 'youtube')
            ->first();

        if (!$auth) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez YouTube.');
        }

        try {

            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $accessToken  = $googleUser->token;
            $refreshToken = $googleUser->refreshToken;
            $expiresIn    = $googleUser->expiresIn;

            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet,statistics',
                    'mine' => 'true',
                ]);

            if (!$response->successful()) {
                Log::error('[YouTube] Erreur récupération chaînes', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return $this->popupResponse(false, 'Impossible de récupérer la chaîne YouTube.');
            }

            $channels = collect($response->json('items', []));

            if ($channels->isEmpty()) {
                return $this->popupResponse(false, 'Aucune chaîne YouTube trouvée pour ce compte Google.');
            }

            // ✅ payload = JSON unique stockant TOUT le contexte temporaire
            // (channels + refresh_token, car social_auth_sessions n'a pas
            // de colonne refresh_token dédiée)
            $auth->update([
                'access_token' => $accessToken,
                'expires_at'   => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'payload'      => [
                    'channels'      => $channels->toArray(),
                    'refresh_token' => $refreshToken, // peut être null si déjà autorisé avant
                ],
            ]);

            Log::info('[YouTube] Session OAuth mise à jour', [
                'auth_id'           => $auth->id,
                'has_access_token'  => !empty($accessToken),
                'has_refresh_token' => !empty($refreshToken),
            ]);

            return $this->popupResponse(
                true,
                'Compte YouTube connecté avec succès',
                [
                    'auth_id'  => $auth->id,
                    'channels' => $channels,
                ]
            );

        } catch (Throwable $e) {

            report($e);

            return $this->popupResponse(false, 'Erreur lors de la connexion YouTube.');
        }
    }

    /**
     * STEP 3 : Store selected YouTube Channel
     */
    public function storeChannel(Request $request, string $siteId)
    {
        $request->validate([
            'auth_id'    => ['required', 'uuid'],
            'channel_id' => ['required', 'string'],
        ]);

        /** @var SocialAuthSession $auth */
        $auth = SocialAuthSession::findOrFail($request->auth_id);

        $site = Site::where('id', $siteId)
            ->where('account_id', $auth->account_id)
            ->firstOrFail();

        if (!$auth->access_token) {
            return response()->json([
                'success' => false,
                'message' => 'Session expirée. Reconnectez YouTube.',
            ], 410);
        }

        // ✅ payload contient maintenant { channels: [...], refresh_token: '...' }
        $payload  = $auth->payload ?? [];
        $channels = collect($payload['channels'] ?? []);

        $channel = $channels->firstWhere('id', $request->channel_id);

        if (!$channel) {
            return response()->json([
                'success' => false,
                'message' => 'Chaîne invalide ou non autorisée',
            ], 403);
        }

        // 🔄 Récupérer le refresh_token de cette session OAuth
        $sessionRefreshToken = $payload['refresh_token'] ?? null;

        // 🔄 Fallback : reprendre celui déjà stocké pour cette chaîne
        // si Google n'en a pas renvoyé un nouveau cette fois
        $existing = SocialAccount::where([
            'site_id'             => $site->id,
            'provider'            => 'youtube',
            'provider_account_id' => $channel['id'],
        ])->first();

        $refreshToken = $sessionRefreshToken ?: $existing?->refresh_token;

        if (!$refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Permission insuffisante : reconnectez votre compte Google en autorisant tous les accès demandés.',
            ], 422);
        }

        SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => 'youtube',
                'provider_account_id' => $channel['id'],
            ],
            [
                'account_name'     => $channel['snippet']['title'] ?? 'Chaîne YouTube',
                'access_token'     => $auth->access_token,
                'refresh_token'    => $refreshToken,
                'token_expires_at' => $auth->expires_at, // ✅ vient de social_auth_sessions.expires_at
                'metadata' => [
                    'channel_title'       => $channel['snippet']['title']       ?? null,
                    'channel_description' => $channel['snippet']['description'] ?? null,
                    'thumbnail'           => $channel['snippet']['thumbnails']['high']['url']
                        ?? $channel['snippet']['thumbnails']['default']['url']
                            ?? null,
                    'subscriber_count' => $channel['statistics']['subscriberCount'] ?? null,
                    'video_count'      => $channel['statistics']['videoCount']      ?? null,
                ],
                'is_active' => true,
            ]
        );

        $auth->delete();

        return response()->json([
            'success' => true,
            'message' => 'Chaîne YouTube connectée avec succès.',
        ]);
    }

    private function popupResponse(bool $ok, string $message, array $data = [])
    {
        return response()->view('social.youtube.popup', [
            'ok'      => $ok ? 'success' : 'error',
            'message' => $message,
            'data'    => $data,
            'origin'  => config('app.frontend_dashboard_url', 'https://elchat.io'),
        ]);
    }

    /**
     * Déconnecter une chaîne YouTube
     * Révoque le token Google + supprime le SocialAccount
     */
    public function disconnect(Request $request, string $siteId): JsonResponse
    {
        $owner = User::findOrFail($request->owner);

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $socialAccount = SocialAccount::where('site_id', $site->id)
            ->where('provider', 'youtube')
            ->where('is_active', true)
            ->firstOrFail();

        try {
            // 1️⃣ Révoquer le token Google (best effort — ne bloque pas la suppression)
            if (!empty($socialAccount->access_token)) {
                $revokeRes = Http::asForm()
                    ->post('https://oauth2.googleapis.com/revoke', [
                        'token' => $socialAccount->access_token,
                    ]);

                Log::info('[YouTube] Révocation token Google', [
                    'account_id' => $socialAccount->id,
                    'status'     => $revokeRes->status(),
                    'success'    => $revokeRes->successful(),
                ]);
            }

            // 2️⃣ Supprimer proprement en base
            $socialAccount->delete();

            Log::info('[YouTube] Chaîne déconnectée', [
                'site_id'    => $site->id,
                'account_id' => $socialAccount->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chaîne YouTube déconnectée avec succès.',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion YouTube.',
            ], 500);
        }
    }
}
