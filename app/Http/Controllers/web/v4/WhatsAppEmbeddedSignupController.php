<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\User;
use App\Services\Social\WhatsApp\EmbeddedSignupService;
use App\Services\Social\WhatsApp\WhatsAppRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppEmbeddedSignupController extends Controller
{
    public function __construct(
        private EmbeddedSignupService      $signupService,
        private WhatsAppRegistrationService $registrationService,
    ) {}

    /**
     * STEP 1 — Reçoit le code du SDK JS Facebook (Angular),
     *           échange contre un token, récupère les WABAs disponibles.
     *
     * Angular appelle cette route après FB.login() avec le code reçu.
     */
    public function exchangeCode(Request $request, string $siteId)
    {
        $request->validate([
            'code'            => ['required', 'string'],
            'phone_number_id' => ['nullable', 'string'], // fourni si l'utilisateur a déjà sélectionné
            'waba_id'         => ['nullable', 'string'],
        ]);

        $owner = User::findOrFail($request->owner ?? auth()->id());

        if (!$owner->ownedAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte associé à cet utilisateur.',
            ], 403);
        }

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        try {

            // ✅ Échanger le code contre un token longue durée
            $tokenData    = $this->signupService->exchangeCode($request->code);
            $accessToken  = $tokenData['access_token'];
            $expiresIn    = $tokenData['expires_in'];

            Log::info('[WhatsApp] Code échangé avec succès', [
                'site_id'      => $siteId,
                'is_long_lived'=> $tokenData['is_long_lived'],
            ]);

            // ✅ Si phone_number_id et waba_id fournis directement par Embedded Signup v4
            // (Meta les envoie via postMessage dans certaines versions)
            if ($request->filled('phone_number_id') && $request->filled('waba_id')) {

                $socialAccount = $this->registrationService->register(
                    site:          $site,
                    wabaId:        $request->waba_id,
                    phoneNumberId: $request->phone_number_id,
                    userAccessToken: $accessToken,
                );

                return response()->json([
                    'success'  => true,
                    'message'  => 'WhatsApp Business connecté avec succès.',
                    'step'     => 'registered',
                    'account'  => [
                        'id'           => $socialAccount->id,
                        'phone'        => $socialAccount->metadata['display_phone']   ?? null,
                        'name'         => $socialAccount->account_name,
                        'waba_id'      => $socialAccount->metadata['waba_id']         ?? null,
                        'phone_number_id' => $socialAccount->metadata['phone_number_id'] ?? null,
                    ],
                ]);
            }

            // ✅ Sinon, récupérer les WABAs disponibles pour que l'utilisateur choisisse
            $accounts = $this->signupService->fetchWhatsAppBusinessAccounts($accessToken);

            if (empty($accounts)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun compte WhatsApp Business trouvé. Vérifiez votre configuration Meta Business.',
                ], 404);
            }

            // ✅ Stocker le token temporairement pour l'étape suivante
            // On l'encode et le renvoie au frontend de façon sécurisée (chiffré)
            $encryptedToken = encrypt(json_encode([
                'access_token' => $accessToken,
                'expires_in'   => $expiresIn,
                'site_id'      => $site->id,
            ]));

            return response()->json([
                'success'         => true,
                'step'            => 'select_phone',
                'accounts'        => $accounts,
                'session_token'   => $encryptedToken, // ← renvoyé au frontend pour l'étape suivante
            ]);

        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion WhatsApp : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * STEP 2 — L'utilisateur sélectionne un numéro dans le frontend,
     *           on finalise l'enregistrement.
     */
    public function selectPhone(Request $request, string $siteId)
    {
        $request->validate([
            'session_token'   => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'waba_id'         => ['required', 'string'],
            'waba_name'       => ['nullable', 'string'],
            'business_id'     => ['nullable', 'string'],
        ]);

        $owner = User::findOrFail($request->owner ?? auth()->id());

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        try {

            // ✅ Déchiffrer le token de session
            $sessionData = json_decode(decrypt($request->session_token), true);

            if (!$sessionData || ($sessionData['site_id'] ?? null) !== $site->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session invalide ou expirée.',
                ], 403);
            }

            $accessToken = $sessionData['access_token'];

            $socialAccount = $this->registrationService->register(
                site:          $site,
                wabaId:        $request->waba_id,
                phoneNumberId: $request->phone_number_id,
                userAccessToken: $accessToken,
                wabaData: [
                    'waba_name'   => $request->waba_name,
                    'business_id' => $request->business_id,
                ],
            );

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp Business connecté avec succès.',
                'account' => [
                    'id'             => $socialAccount->id,
                    'phone'          => $socialAccount->metadata['display_phone']    ?? null,
                    'name'           => $socialAccount->account_name,
                    'waba_id'        => $socialAccount->metadata['waba_id']          ?? null,
                    'phone_number_id'=> $socialAccount->metadata['phone_number_id']  ?? null,
                ],
            ]);

        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Déconnecter un numéro WhatsApp Business
     */
    public function disconnect(Request $request, string $siteId)
    {
        $owner = User::findOrFail($request->owner ?? auth()->id());

        $site = Site::where('id', $siteId)
            ->where('account_id', $owner->ownedAccount->id)
            ->firstOrFail();

        $account = SocialAccount::where('site_id', $site->id)
            ->where('provider', 'whatsapp')
            ->where('is_active', true)
            ->firstOrFail();

        $wabaId      = $account->metadata['waba_id']    ?? null;
        $accessToken = $account->access_token;

        if ($wabaId && $accessToken) {
            $this->registrationService->unsubscribeFromWaba($wabaId, $accessToken);
        }

        $account->update(['is_active' => false]);

        Log::info('[WhatsApp] Compte déconnecté', ['account_id' => $account->id]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp Business déconnecté.',
        ]);
    }
}
