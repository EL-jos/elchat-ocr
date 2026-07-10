<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialAuthSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class InstagramConnectController extends Controller
{
    public string $version;

    public function __construct()
    {
        $this->version = config('services.facebook.graph_version', '25.0');
    }

    /**
     * STEP 1 : Redirect OAuth Facebook (Instagram Business passe par Facebook Login)
     */
    public function redirect(Request $request, string $siteId)
    {
        $owner = User::findOrFail($request->owner);

        if (!$owner->ownedAccount) {
            return $this->popupResponse(false, 'Aucun compte associé à cet utilisateur.');
        }

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $session = SocialAuthSession::create([
            'site_id'    => $site->id,
            'account_id' => $owner->ownedAccount->id, // ✅ cohérent avec ownedAccount
            'provider'   => 'instagram',
        ]);

        return Socialite::driver('facebook')
            ->scopes([
                'instagram_basic',
                'instagram_manage_messages',
                'instagram_manage_comments',   // ✅ requis pour lire/répondre aux commentaires IG
                'pages_show_list',
                'pages_read_engagement',        // ✅ requis pour subscribed_apps
                'pages_manage_metadata',
                'business_management',
            ])
            ->with([
                'auth_type' => 'rerequest',
                'state'     => $session->id,
            ])
            ->redirect();
    }

    /**
     * STEP 2 : Callback OAuth Facebook
     */
    public function callback(Request $request)
    {
        $authId = $request->state;

        if (!$authId) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez Instagram.');
        }

        /** @var SocialAuthSession|null $auth */
        $auth = SocialAuthSession::where('id', $authId)
            ->where('provider', 'instagram')
            ->first();

        if (!$auth) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez Instagram.');
        }

        try {

            $facebookUser = Socialite::driver('facebook')
                ->stateless()
                ->user();

            $token = $facebookUser->token;

            $pagesResponse = Http::get(
                "https://graph.facebook.com/{$this->version}/me/accounts",
                ['access_token' => $token]
            );

            if (!$pagesResponse->successful()) {
                Log::error('[Instagram] Erreur récupération pages', [
                    'status' => $pagesResponse->status(),
                    'body'   => $pagesResponse->body(),
                ]);

                return $this->popupResponse(false, 'Impossible de récupérer les pages Facebook.');
            }

            $pages = collect($pagesResponse->json('data', []));

            if ($pages->isEmpty()) {
                return $this->popupResponse(false, 'Aucune page Facebook trouvée.');
            }

            $accounts = [];

            foreach ($pages as $page) {

                $igResponse = Http::get(
                    "https://graph.facebook.com/{$this->version}/{$page['id']}",
                    [
                        'fields'       => 'instagram_business_account',
                        'access_token' => $page['access_token'],
                    ]
                );

                if (!$igResponse->successful()) {
                    Log::warning('[Instagram] Impossible de vérifier le compte IG lié', [
                        'page_id' => $page['id'],
                        'status'  => $igResponse->status(),
                    ]);
                    continue;
                }

                $instagramId = $igResponse->json('instagram_business_account.id');

                if (!$instagramId) {
                    // ✅ Cette page n'a pas de compte Instagram Business lié — normal, on ignore
                    continue;
                }

                $profileResponse = Http::get(
                    "https://graph.facebook.com/{$this->version}/{$instagramId}",
                    [
                        'fields'       => 'id,username,profile_picture_url,name',
                        'access_token' => $page['access_token'],
                    ]
                );

                $profile = $profileResponse->successful() ? $profileResponse->json() : [];

                $accounts[] = [
                    'page_id'           => $page['id'],
                    'page_access_token' => $page['access_token'],
                    'instagram_id'      => $instagramId,
                    'username'          => $profile['username'] ?? null,
                    'name'              => $profile['name']     ?? null,
                    'picture'           => $profile['profile_picture_url'] ?? null,
                ];
            }

            if (empty($accounts)) {
                return $this->popupResponse(
                    false,
                    'Aucun compte Instagram Business lié à vos pages Facebook. Liez votre compte Instagram à une Page Facebook depuis les paramètres Meta Business Suite.'
                );
            }

            $auth->update([
                'access_token' => $token,
                'payload'      => $accounts,
            ]);

            return $this->popupResponse(
                true,
                'Instagram connecté avec succès',
                [
                    'auth_id'  => $auth->id,
                    'accounts' => $accounts,
                ]
            );

        } catch (Throwable $e) {

            report($e);

            return $this->popupResponse(false, 'Erreur lors de la connexion Instagram.');
        }
    }

    /**
     * STEP 3 : Store selected Instagram Account + Subscribe Webhook
     */
    public function storeAccount(Request $request, string $siteId)
    {
        $request->validate([
            'auth_id'      => ['required', 'uuid'],
            'instagram_id' => ['required', 'string'],
        ]);

        /** @var SocialAuthSession $auth */
        $auth = SocialAuthSession::findOrFail($request->auth_id);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $auth->account_id)
            ->firstOrFail();

        $account = collect($auth->payload)->firstWhere('instagram_id', $request->instagram_id);

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Compte Instagram invalide ou non autorisé',
            ], 403);
        }

        // 📡 Abonner la Page Facebook liée au webhook (champ "comments" pour Instagram)
        // ✅ Idempotent : si déjà abonnée via FacebookConnectController::storePage(),
        // Meta fusionne les subscribed_fields sans écraser feed/messages existants.
        $subscribeResponse = Http::post(
            "https://graph.facebook.com/{$this->version}/{$account['page_id']}/subscribed_apps",
            [
                'subscribed_fields' => 'comments,mentions,messages,messaging_postbacks',
                'access_token'      => $account['page_access_token'],
            ]
        );

        $webhookSubscribed = $subscribeResponse->successful()
            && $subscribeResponse->json('success') === true;

        if (!$webhookSubscribed) {
            Log::warning('[Instagram] Échec abonnement webhook', [
                'page_id' => $account['page_id'],
                'status'  => $subscribeResponse->status(),
                'body'    => $subscribeResponse->body(),
            ]);
        }

        SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => 'instagram',
                'provider_account_id' => $account['instagram_id'],
            ],
            [
                'account_name'  => $account['username'] ?? 'Instagram',
                'access_token'  => $account['page_access_token'],
                'refresh_token' => null,
                'metadata' => [
                    'instagram_id'          => $account['instagram_id'],
                    'page_id'               => $account['page_id'],
                    'username'              => $account['username'] ?? null,
                    'name'                  => $account['name']     ?? null,
                    'picture'               => $account['picture']  ?? null,
                    'webhook_subscribed'    => $webhookSubscribed,
                    'webhook_fields'        => $webhookSubscribed ? 'comments,mentions,messages' : null,
                    'webhook_subscribed_at' => $webhookSubscribed
                        ? now()->toIso8601String()
                        : null,
                ],
                'is_active' => true,
            ]
        );

        $auth->delete();

        return response()->json([
            'success'            => true,
            'message'            => 'Compte Instagram connecté avec succès.',
            'webhook_subscribed' => $webhookSubscribed,
            'account' => [
                'instagram_id' => $account['instagram_id'],
                'username'     => $account['username'] ?? null,
                'picture'      => $account['picture']  ?? null,
            ],
        ]);
    }

    /**
     * POPUP RESPONSE (Angular bridge)
     */
    private function popupResponse(bool $ok, string $message, array $data = [])
    {
        $origin = config('app.frontend_dashboard_url', 'https://elchat.io');

        return response()->view('social.instagram.popup', [
            'ok'      => $ok ? 'success' : 'error',
            'message' => $message,
            'data'    => $data,
            'origin'  => $origin,
        ]);
    }
}
