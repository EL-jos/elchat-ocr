<?php

namespace App\Http\Controllers\web\v1;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Models\Account;
use App\Models\Role;
use App\Models\Site;
use App\Models\UserVerification;
use App\Services\AuditLogger;
use Exception;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class GoogleController extends Controller
{
    // Redirection vers Google
    public function redirect(Request $request)
    {
        $siteId = $request->query('site_id');
        $mode = $request->query('mode');

        if (!$siteId) {
            return $this->errorResponse(
                'missing_site_id',
                'Le paramètre site_id est requis pour la connexion Google.'
            );
        }

        // Stocke temporairement le site_id en session
        session(['google_oauth_site_id' => $siteId]);
        session(['google_oauth_mode' => $mode]);

        return Socialite::driver('google')->redirect();
    }

    // Callback Google
    public function callback()
    {
        $siteId = session()->pull('google_oauth_site_id'); // récupère ET supprime
        $mode = session()->pull('google_oauth_mode'); // récupère ET supprime

        if (!$siteId) {
            return $this->errorResponse(
                'invalid_or_expired_site_id',
                'Impossible de récupérer le site associé. La session a peut-être expiré.'
            );
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Exception $e) {
            Log::error('Google OAuth error: '.$e->getMessage());
            return $this->errorResponse('oauth_error', 'Erreur lors de la récupération des informations Google.', 500);
        }

        $user = User::where('google_id', $googleUser->getId())->whereNull('deleted_at')->first();

        if ($mode === 'login'){

            if (!$user) {
                AuditLogger::event(
                    'login_failed',
                    'User',
                    null,
                    [],
                    ['reason' => 'user_not_found']
                );
                return $this->errorResponse('user_not_found', 'Aucun compte associé à ce compte Google.', 404);
            }

            if (!$user->is_verified) {
                AuditLogger::event(
                    'login_failed',
                    'User',
                    $user->id,
                    [],
                    ['reason' => 'account_not_verified']
                );

                return response()->json([
                    'error' => 'account_not_verified',
                    'message' => 'Votre compte n’est pas encore vérifié. Veuillez entrer le code de confirmation.'
                ], 403);
            }

            // Générer le token JWT
            $token = JWTAuth::fromUser($user);

            AuditLogger::event(
                'login',
                'User',
                Auth::id(),
                [],
                ['logged_in_at' => now()]
            );

            $this->attachUserToSiteIfNeeded($user, $siteId);

            $data = [
                'ok' => true,
                'user' => $user,
                'token' => $token,
                'message' => "Connexion effectuée avec succès."
            ];

            Log::info("test login google", $data);

            return response()->view('auth.google-callback-login', compact('data'));

        }

        // Prépare le payload pour ton API register
        $nameParts = explode(' ', $googleUser->getName(), 2);

        $payload = [
            'firstname' => $nameParts[0] ?? '',
            'lastname'  => $nameParts[1] ?? '',
            'email'     => $googleUser->getEmail(),
            'password'  => null, // on génère côté API si vide
            'is_admin'  => false, // par défaut false, tu peux adapter
            'site_id'   => $siteId,
            'google_id' => $googleUser->getId(),
            'phone'     => null,
        ];

        $user = null;
        $code = null;

        try {

            DB::transaction(function () use (&$payload, &$user, &$code){
                $role = null;
                if($payload['is_admin']){
                    $role = Role::where('name', 'admin')->first();
                }else{
                    $role = Role::where('name', 'visitor')->first();
                }

                /**
                 * @var User $user
                 */
                // 1️⃣ Create user
                $user = User::firstOrCreate([
                    'google_id' => $payload['google_id'], // condition pour vérifier si l'utilisateur existe
                ], [
                    'role_id' => $role->id,
                    'firstname' => $payload['firstname'],
                    'lastname' => $payload['lastname'],
                    'email'     => $payload['email'],
                    'phone'     => $payload['phone'],
                    'password'  => Hash::make($payload['password'] ?? Str::random(16)),
                    'is_verified' => false,
                    'google_id' => $payload['google_id']
                ]);

                if($user->isAdmin()  && !$user->ownedAccount){
                    // Créer un compte associé à l'utilisateur
                    $account = Account::create([
                        'name' => $payload['account_name'],
                        'email' => $payload['email'],
                        'owner_user_id' => $user->id,
                    ]);
                }

                if ($user->isVisitor()) {
                    $site = Site::findOrFail($payload['site_id']);

                    $site->users()->syncWithoutDetaching([
                        $user->id => [
                            'first_seen_at' => now(),
                            'last_seen_at' => now(),
                        ]
                    ]);
                }

                // 3️⃣ Create verification code
                $code = UserVerification::generateCode();

                UserVerification::create([
                    'user_id' => $user->id,
                    'code' => hash('sha256', $code),
                    'attempts' => 0,
                    'expires_at' => now()->addMinute(),
                    'type' => 'email_verification'
                ]);

                AuditLogger::event(
                    'register',
                    'User',
                    $user->id,
                    [],
                    ['role' => $user->role, 'email' => $user->email]
                );

            });

            DB::afterCommit(function () use (&$user, &$code) {
                // 4️⃣ Send verification email
                Mail::to($user->email)->send(new VerificationCodeMail($user, $code, 1));
            });

            /*return response()->json([
                'ok' => true,
                'message' => 'Compte créé. Vérifiez votre email ou téléphone pour le code.',
                'user' => $user,
            ], 201);*/

            return response()->view('auth.google-callback', [
                'ok' => true,
                'user' => $user,
                'message' => 'Compte créé. Vérifiez votre email ou téléphone pour le code.'
            ]);

        } catch (Throwable $e) {
            Log::error('Register error: '.$e->getMessage());
            return response()->json(['error' => 'server_error'], 500);
        }
    }

    protected function errorResponse(string $errorCode, string $message, int $status = 400)
    {
        return response()->json([
            'ok' => false,
            'error' => $errorCode,
            'message' => $message
        ], $status);
    }

    private function attachUserToSiteIfNeeded(User $user, ?string $siteId): void
    {
        if (! $siteId) {
            return;
        }

        $now = now();

        if (! $user->sites()->where('site_id', $siteId)->exists()) {

            // Première visite
            $user->sites()->attach($siteId, [
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]);

        } else {

            // Déjà lié → on met à jour uniquement last_seen_at
            $user->sites()->updateExistingPivot($siteId, [
                'last_seen_at' => $now,
            ]);
        }
    }
}
