<?php

namespace App\Http\Controllers\web\v4;

use App\Enums\Social\SocialProvider;
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
        $facebookFields = 'feed,messages';

        $instagramFields = 'comments,messages';

        $allWebhookFields = implode(',', [
            'feed',
            'messages',
            'comments',
        ]);

        $subscribeResponse = Http::post(
            "https://graph.facebook.com/{$this->version}/{$page['id']}/subscribed_apps",
            [
                'subscribed_fields' => "feed,messages",
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

        // 🧠 Create / update Social Account
        $socialAccount = SocialAccount::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => SocialProvider::FACEBOOK->value,
                'provider_account_id' => $page['id'],
            ],
            [
                'account_name' => $page['name'] ?? 'Facebook Page',
                // ⚠️ Page access token (IMPORTANT)
                'access_token' => $page['access_token'],
                'refresh_token' => null,
                'token_expires_at' => null,
                // 🔐 safe metadata only
                'metadata' => [
                    'name' => $page['name'] ?? null,
                    'category' => $page['category'] ?? null,
                    'tasks' => $page['tasks'] ?? [],
                    'picture' => $page['picture'] ?? null,
                    'webhook_subscribed'=> $webhookSubscribed,
                    'webhook_fields'    => $webhookSubscribed ? $facebookFields : null,
                    'webhook_subscribed_at' => $webhookSubscribed
                        ? now()->toIso8601String()
                        : null,
                ],

                'is_active' => true,
            ]
        );

        // =====================================================
        // INSTAGRAM BUSINESS ACCOUNT (si lié à la Page)
        // =====================================================

        $instagramAccount = null;

        try {

            $instagramResponse = Http::timeout(15)
                ->get(
                    "https://graph.facebook.com/{$this->version}/{$page['id']}",
                    [
                        'fields' => 'instagram_business_account{id}',
                        'access_token' => $page['access_token'],
                    ]
                );

            if ($instagramResponse->successful()) {

                $instagramBusinessId = data_get(
                    $instagramResponse->json(),
                    'instagram_business_account.id'
                );

                if ($instagramBusinessId) {

                    $instagramDetailsResponse = Http::timeout(15)
                        ->get(
                            "https://graph.facebook.com/{$this->version}/{$instagramBusinessId}",
                            [
                                'fields' => 'id,username,name,profile_picture_url',
                                'access_token' => $page['access_token'],
                            ]
                        );

                    if ($instagramDetailsResponse->successful()) {

                        $instagram = $instagramDetailsResponse->json();

                        // =====================================================
                        // WEBHOOK INSTAGRAM
                        // =====================================================

                        $instagramSubscribeResponse = Http::timeout(15)
                            ->post(
                                "https://graph.facebook.com/{$this->version}/{$page['id']}/subscribed_apps",
                                [
                                    'subscribed_fields' => "comments,messages",
                                    'access_token' => $page['access_token'],
                                ]
                            );

                        $instagramWebhookSubscribed =
                            $instagramSubscribeResponse->successful()
                            && $instagramSubscribeResponse->json('success') === true;

                        if (!$instagramWebhookSubscribed) {

                            Log::warning(
                                '[Instagram] Échec abonnement webhook',
                                [
                                    'instagram_id' => $instagramBusinessId,
                                    'facebook_page_id' => $page['id'],
                                    'status' => $instagramSubscribeResponse->status(),
                                    'body' => $instagramSubscribeResponse->body(),
                                ]
                            );
                        }

                        $instagramAccount = SocialAccount::updateOrCreate(
                            [
                                'site_id' => $site->id,
                                'provider' => SocialProvider::INSTAGRAM->value,
                                'provider_account_id' => $instagram['id'],
                            ],
                            [
                                'account_name' => $instagram['username']
                                    ?? $instagram['name']
                                        ?? 'Instagram',

                                /**
                                 * Instagram Messaging utilise le Page Token
                                 */
                                'access_token' => $page['access_token'],

                                'refresh_token' => null,

                                'token_expires_at' => null,

                                'metadata' => [

                                    'instagram_id' => $instagram['id'],

                                    'username' => $instagram['username'] ?? null,

                                    'name' => $instagram['name'] ?? null,

                                    'picture' => $instagram['profile_picture_url'] ?? null,

                                    'facebook_page_id' => $page['id'],

                                    'facebook_page_name' => $page['name'] ?? null,

                                    'webhook_subscribed' => $instagramWebhookSubscribed,

                                    'webhook_fields' => $instagramWebhookSubscribed
                                        ? $instagramFields
                                        : null,

                                    'webhook_subscribed_at' => $instagramWebhookSubscribed
                                        ? now()->toIso8601String()
                                        : null,
                                ],

                                'is_active' => true,
                            ]
                        );

                        Log::info(
                            '[Instagram] Compte Instagram lié enregistré',
                            [
                                'instagram_id' => $instagram['id'],
                                'username' => $instagram['username'] ?? null,
                                'facebook_page_id' => $page['id'],
                                'site_id' => $site->id,
                                'webhook_subscribed' => $instagramWebhookSubscribed,
                            ]
                        );
                    }
                }
            }

        } catch (\Throwable $e) {

            report($e);

            Log::warning(
                '[Instagram] Impossible de récupérer le compte lié',
                [
                    'facebook_page_id' => $page['id'],
                    'message' => $e->getMessage(),
                ]
            );
        }

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
