<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialAuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class FacebookConnectController extends Controller
{
    public string $version;
    public function __construct()
    {
        $this->version = config('services.facebook.graph_version', "25.0");
    }

    /**
     * STEP 1 : Redirect OAuth Facebook
     */
    public function redirect(Request $request, string $siteId)
    {
        $ownerId = $request->owner;
        $owner = User::findOrFail($ownerId);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        // 🔐 Binder le site dans la bdd (multi-tenant safe)
        $socialAuthSession = SocialAuthSession::create([
            'site_id' => $site->id,
            'account_id' => $owner->ownedAccount->id,
            'provider' => 'facebook',
        ]);

        if ($socialAuthSession) {
            return Socialite::driver('facebook')
                ->scopes([
                    'pages_show_list',
                    'pages_read_engagement',
                    'pages_manage_engagement',
                    'pages_manage_posts',
                    'pages_manage_metadata',
                    'pages_messaging',
                    'business_management',
                    'instagram_basic',
                    'instagram_manage_messages'
                ])
                ->with([
                    'auth_type' => 'rerequest',
                    'state' => $socialAuthSession->id
                ])
                ->redirect();
        }
    }

    /**
     * STEP 2 : Callback OAuth Facebook
     */
    public function callback(Request $request)
    {
        $authId = $request->state;

        if (!$authId) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez Facebook.');
        }

        /** @var SocialAuthSession $auth */
        $auth = SocialAuthSession::findOrFail($authId);


        /** @var Site $site */
        $site = Site::where('id', $auth->site_id)
            ->where('account_id', $auth->account_id)
            ->firstOrFail();

        try {

            //dd($auth, $site);
            // 🔐 User Facebook OAuth
            $facebookUser = Socialite::driver('facebook')
                ->stateless()
                ->user();

            //dd($auth, $site, $facebookUser);

            $userAccessToken = $facebookUser->token;

            // 🔥 Fetch Pages Facebook
            $pagesResponse = Http::get(
                "https://graph.facebook.com/{$this->version}/me/accounts",
                [
                    'access_token' => $userAccessToken,
                ]
            );

            if (!$pagesResponse->successful()) {
                return $this->popupResponse(false, 'Impossible de récupérer les pages Facebook');
            }

            $pages = $pagesResponse->json('data', []);

            if (empty($pages)) {
                return $this->popupResponse(false, 'Aucune page Facebook trouvée');
            }

            $pages = collect($pages)->map(function ($page) {

                $response = Http::get("https://graph.facebook.com/{$this->version}/{$page['id']}/picture", [
                    'type' => 'large',
                    'redirect' => false,
                    'access_token' => $page['access_token'],
                ]);

                $page['picture'] = $response->json('data.url');

                return $page;
            });

            // 🧠 Store temporarily for next step (page selection)

            // 🔥 STORE DANS DB (PAS SESSION)
            $isUpdate = $auth->update([
                'access_token' => $userAccessToken,
                'payload' => $pages,
            ]);

            if ($isUpdate){
                // 👉 Return pages directly to Angular popup
                return $this->popupResponse(
                    true,
                    'Facebook connecté avec succès', [
                        'auth_id' => $auth->id,
                        'pages' => $pages
                    ]
                );
            }

        } catch (Throwable $e) {

            report($e);

            return $this->popupResponse(
                false,
                'Erreur lors de la connexion Facebook: '
            );
        }
    }

    /**
     * STEP 4 : Store selected Facebook Page
     */
    public function storePage(Request $request, string $siteId)
    {

        $request->validate([
            'page_id' => ['required', 'string'],
            'auth_id' => ['required', 'uuid'],
        ]);

        /** @var SocialAuthSession $auth */
        $auth = SocialAuthSession::findOrFail($request->auth_id);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $auth->account_id)
            ->firstOrFail();

        $pages = collect($auth->payload);

        // 🔥 Récupérer les pages fraîchement depuis Facebook
        $pagesResponse = Http::get(
            "https://graph.facebook.com/{$this->version}/me/accounts",
            [
                'access_token' => $auth->access_token,
            ]
        );

        if (!$pagesResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les pages Facebook'
            ], 502);
        }

        $pages = collect($pagesResponse->json('data'));

        if ($pages->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune page Facebook trouvée'
            ], 404);
        }

        // 🔐 Ensure page exists in session (security)
        $page = $pages->firstWhere('id', $request->page_id);

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Page invalide ou non autorisée'
            ], 403);
        }

        // 🖼️ Récupérer la photo de la page
        $pictureResponse = Http::get("https://graph.facebook.com/{$this->version}/{$page['id']}/picture", [
            'type' => 'large',
            'redirect' => false,
            'access_token' => $page['access_token'],
        ]);

        $page['picture'] = $pictureResponse->successful()
            ? $pictureResponse->json('data.url')
            : null ;

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Page invalide ou non autorisée'
            ], 403);
        }

        // 📡 Abonner la Page au Webhook de l'app (CRUCIAL - manquant avant)
        $fields = 'feed,messages';
        $subscribeResponse = Http::post(
            "https://graph.facebook.com/{$this->version}/{$page['id']}/subscribed_apps",
            [
                'subscribed_fields' => $fields,
                'access_token'      => $page['access_token'],
            ]
        );

        // Ajoute ça :
        Log::info('[Facebook] Subscribe webhook response', [
            'status' => $subscribeResponse->status(),
            'body'   => $subscribeResponse->json(),
            'page_id' => $page['id'],
        ]);

        $webhookSubscribed = $subscribeResponse->successful() && $subscribeResponse->json('success') === true;

        // 🔥 Échanger le short-lived page token contre un long-lived token
        $longLivedResponse = Http::get("https://graph.facebook.com/{$this->version}/oauth/access_token", [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $page['access_token'],
        ]);

        $longLivedToken  = $longLivedResponse->successful()
            ? $longLivedResponse->json('access_token')
            : $page['access_token']; // fallback sur le token original

        $expiresIn       = $longLivedResponse->json('expires_in'); // en secondes
        $tokenExpiresAt  = $expiresIn
            ? now()->addSeconds($expiresIn)
            : now()->addDays(60); // fallback conservateur Facebook

        // 🧠 Create / update Social Account
        $socialAccount = SocialAccount::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => 'facebook',
                'provider_account_id' => $page['id'],
            ],
            [
                'account_name' => $page['name'] ?? 'Facebook Page',
                // ⚠️ Page access token (IMPORTANT)
                //'access_token' => $page['access_token'],
                'access_token'    => $longLivedToken,
                'refresh_token' => null,
                //'token_expires_at' => null,
                'token_expires_at'=> $tokenExpiresAt,
                // 🔐 safe metadata only
                'metadata' => [
                    'name' => $page['name'] ?? null,
                    'category' => $page['category'] ?? null,
                    'tasks' => $page['tasks'] ?? [],
                    'picture' => $page['picture'] ?? null,
                    'webhook_subscribed'=> $webhookSubscribed,
                    'webhook_fields'    => $webhookSubscribed ? $fields : null,
                    'webhook_subscribed_at' => $webhookSubscribed
                        ? now()->toIso8601String()
                        : null,
                ],

                'is_active' => true,
            ]
        );

        if($socialAccount){
            // 🧹 Clean session
            session()->forget([
                'facebook_pages',
                'facebook_user_token',
                'facebook_site_id',
                'facebook_user_id',
                'state',
                'account_id'
            ]);
            // 🧹 Nettoyer la session temporaire OAuth
            $auth->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Page connectée avec succès'
        ]);
    }

    /**
     * Déconnecter UNE page Facebook spécifique
     */
    public function disconnectPage(Request $request, string $siteId, string $pageId)
    {
        $ownerId = $request->owner;
        $owner = User::findOrFail($ownerId);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        /** @var SocialAccount $socialAccount */
        $socialAccount = SocialAccount::where('site_id', $site->id)
            ->where('provider', 'facebook')
            ->where('provider_account_id', $pageId)
            ->firstOrFail();

        return $this->performFacebookDisconnect($site, collect([$socialAccount]));
    }

    /**
     * Déconnecter TOUTES les pages Facebook du site
     */
    public function disconnect(Request $request, string $siteId)
    {
        $ownerId = $request->owner;
        $owner = User::findOrFail($ownerId);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $socialAccounts = SocialAccount::where('site_id', $site->id)
            ->where('provider', 'facebook')
            ->get();

        if ($socialAccounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte Facebook connecté',
            ], 404);
        }

        return $this->performFacebookDisconnect($site, $socialAccounts);
    }

    /**
     * Logique commune de déconnexion (Best effort sur l'API Facebook)
     */
    private function performFacebookDisconnect(Site $site, Collection $accounts): JsonResponse
    {
        $disconnected = [];
        $failed       = [];

        foreach ($accounts as $account) {
            try {
                // 1️⃣ Désabonner le webhook
                if (!empty($account->access_token)) {
                    $unsubRes = Http::delete(
                        "https://graph.facebook.com/{$this->version}/{$account->provider_account_id}/subscribed_apps",
                        ['access_token' => $account->access_token]
                    );

                    Log::info('[Facebook] Unsubscribe webhook', [
                        'page_id' => $account->provider_account_id,
                        'status'  => $unsubRes->status(),
                        'body'    => $unsubRes->json(),
                    ]);
                }

                // 2️⃣ Révoquer les permissions (best effort, ne bloque pas)
                if (!empty($account->access_token)) {
                    Http::delete("https://graph.facebook.com/{$this->version}/me/permissions", [
                        'access_token' => $account->access_token,
                    ]);
                }

                // 3️⃣ Supprimer en base
                $account->delete();

                $disconnected[] = $account->provider_account_id;

            } catch (Throwable $e) {
                report($e);
                $failed[] = $account->provider_account_id;

                Log::error('[Facebook] Disconnect failed', [
                    'page_id' => $account->provider_account_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // ✅ Succès total
        if (empty($failed)) {
            return response()->json([
                'success'      => true,
                'message'      => count($disconnected) > 1
                    ? count($disconnected) . ' pages Facebook déconnectées avec succès'
                    : 'Page Facebook déconnectée avec succès',
                'disconnected' => $disconnected,
            ]);
        }

        // ⚠️ Succès partiel
        if (!empty($disconnected)) {
            return response()->json([
                'success'      => false,
                'message'      => 'Déconnexion partielle — certaines pages ont échoué',
                'disconnected' => $disconnected,
                'failed'       => $failed,
            ], 207);
        }

        // ❌ Échec total
        return response()->json([
            'success' => false,
            'message' => 'Échec de la déconnexion Facebook',
            'failed'  => $failed,
        ], 500);
    }

    /**
     * POPUP RESPONSE (Angular bridge)
     */
    private function popupResponse(bool $ok, string $message, array $data = [])
    {
        $origin = config('app.frontend_dashboard_url', 'https://elchat.io');

        return response()->view('social.facebook.popup', [
            'ok' => $ok ? "success" : "error",
            'message' => $message,
            'data' => $data,
            'origin' => $origin,
        ]);
    }
}
