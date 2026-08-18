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

class SlackConnectController extends Controller
{
    /**
     * STEP 1 : Redirect OAuth Slack
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

        $auth = SocialAuthSession::create([
            'site_id'    => $site->id,
            'account_id' => $owner->ownedAccount->id,
            'provider'   => 'slack',
        ]);

        return Socialite::driver('slack')
            ->scopes([
                //'assistant:write',
                'chat:write',
                //'channels:read',
                //'channels:history',
                //'groups:read',
                //'groups:history',
                //'channels:manage',
            ])
            ->with([
                'state' => $auth->id,
            ])
            ->redirect();
    }

    /**
     * STEP 2 : Callback OAuth Slack
     *
     * ⚠️ Contrairement à Facebook/YouTube/Instagram, Slack ne
     * nécessite PAS d'étape de sélection ("choisir une page/chaîne") :
     * l'utilisateur autorise le Bot sur UN SEUL Workspace à la fois,
     * et Slack renvoie directement team_id + access_token ici.
     * Donc pas de storeXxx() séparé : tout se fait dans ce callback.
     */
    public function callback(Request $request)
    {
        Log::info('Slack callback', $request->all());
        $authId = $request->state;

        if (!$authId) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez Slack.');
        }

        /** @var SocialAuthSession|null $auth */
        $auth = SocialAuthSession::where('id', $authId)
            ->where('provider', 'slack')
            ->first();

        if (!$auth) {
            return $this->popupResponse(false, 'Session expirée. Reconnectez Slack.');
        }

        try {

            $slackUser = Socialite::driver('slack')
                ->stateless()
                ->user();

            // ✅ Socialite Slack expose le payload OAuth brut via ->user
            $raw = $slackUser->user;

            $botToken   = $raw['access_token']   ?? null; // xoxb-...
            $teamId     = $raw['team']['id']     ?? null;
            $teamName   = $raw['team']['name']   ?? null;
            $botUserId  = $raw['bot_user_id']    ?? null;
            $appId      = $raw['app_id']         ?? null;
            $scope      = $raw['scope']          ?? null;

            if (!$botToken || !$teamId) {
                Log::error('[Slack] Réponse OAuth incomplète', ['raw' => $raw]);
                return $this->popupResponse(false, 'Réponse Slack incomplète. Réessayez.');
            }

            $site = Site::where('id', $auth->site_id)
                ->where('account_id', $auth->account_id)
                ->firstOrFail();

            // ✅ Vérifier que le token fonctionne réellement (auth.test)
            $testResponse = Http::withToken($botToken)
                ->asForm()
                ->post('https://slack.com/api/auth.test');

            if (!$testResponse->successful() || !$testResponse->json('ok')) {
                Log::error('[Slack] auth.test échoué', [
                    'status' => $testResponse->status(),
                    'body'   => $testResponse->json(),
                ]);
                return $this->popupResponse(false, 'Impossible de valider la connexion Slack.');
            }

            // 🔥 Récupérer les channels publics du workspace pour affichage UI
            // (le Bot ne reçoit les messages QUE des channels où il est invité,
            // mais on liste les channels publics existants pour information)
            $channels = $this->fetchPublicChannels($botToken);

            SocialAccount::updateOrCreate(
                [
                    'site_id'             => $site->id,
                    'provider'            => 'slack',
                    'provider_account_id' => $teamId,
                ],
                [
                    'account_name'     => $teamName ?? 'Slack Workspace',
                    'access_token'     => $botToken,
                    'refresh_token'    => null, // Slack Bot tokens n'expirent pas (sauf rotation activée)
                    'token_expires_at' => null,
                    'metadata' => [
                        'team_id'      => $teamId,
                        'team_name'    => $teamName,
                        'bot_user_id'  => $botUserId,
                        'app_id'       => $appId,
                        'scope'        => $scope,
                        'channels'     => $channels,
                    ],
                    'is_active' => true,
                ]
            );

            $auth->delete();

            return $this->popupResponse(
                true,
                'Workspace Slack connecté avec succès',
                [
                    'team_name' => $teamName,
                    'channels'  => $channels,
                ]
            );

        } catch (Throwable $e) {

            report($e);

            return $this->popupResponse(false, 'Erreur lors de la connexion Slack.');
        }
    }

    /**
     * Liste les channels publics du workspace (pour affichage informatif
     * dans l'UI — le Bot doit être /invite manuellement par l'utilisateur
     * dans chaque channel pour y recevoir les messages, Slack ne permet
     * pas à un Bot de rejoindre automatiquement tous les channels).
     */
    private function fetchPublicChannels(string $botToken): array
    {
        $response = Http::withToken($botToken)
            ->get('https://slack.com/api/conversations.list', [
                'types'           => 'public_channel,private_channel',
                'exclude_archived'=> true,
                'limit'           => 200,
            ]);

        if (!$response->successful() || !$response->json('ok')) {
            Log::warning('[Slack] Impossible de lister les channels', [
                'body' => $response->json(),
            ]);
            return [];
        }

        return collect($response->json('channels', []))
            ->map(fn ($c) => [
                'id'         => $c['id'],
                'name'       => $c['name'],
                'is_private' => $c['is_private'] ?? false,
                'is_member'  => $c['is_member']  ?? false,
            ])
            ->values()
            ->toArray();
    }

    private function popupResponse(bool $ok, string $message, array $data = [])
    {
        $origin = config('app.frontend_dashboard_url', 'https://elchat.io');

        return response()->view('social.slack.popup', [
            'ok'      => $ok ? 'success' : 'error',
            'message' => $message,
            'data'    => $data,
            'origin'  => $origin,
        ]);
    }
}
