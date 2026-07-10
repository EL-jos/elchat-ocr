<?php

namespace App\Http\Controllers\api\v1;

use App\Mail\ResetPasswordCodeMail;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\Site;
use App\Models\Visitor;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\Facebook;
use Google\Client as GoogleClient;
use OpenApi\Annotations as OA;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\VerifyCodeRequest;
use App\Mail\VerificationCodeMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Models\UserVerification;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/register",
     *     summary="Créer un compte utilisateur",
     *     description="Crée un compte utilisateur et envoie un code de vérification par email.",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"firstname","lastname","email","password"},
     *             @OA\Property(property="firstname", type="string", example="John"),
     *             @OA\Property(property="lastname", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="Secret123!")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Compte créé avec succès",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Erreur de validation"),
     *     @OA\Response(
     *          response=500,
     *          description="Erreur serveur",
     *          @OA\JsonContent(
     *              @OA\Property(property="error", type="string", example="server_error")
     *          )
     *      )
     * )
     */
    public function register(RegisterRequest $request)
    {
        $payload = $request->validated();
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
                $user = User::create([
                    'role_id' => $role->id,
                    'firstname' => $payload['firstname'],
                    'lastname' => $payload['lastname'],
                    'email'     => $payload['email'],
                    'phone'     => $payload['phone'],
                    'password'  => Hash::make($payload['password'] ?? Str::random(16)),
                    'is_verified' => false,
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
                    'expires_at' => now()->addMinute(5),
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

            // 5️⃣ Optionally send WhatsApp/SMS
            /*$message = "Votre code de vérification : {$code} (expires in 1 minute)";
            MessageSender::sendWhatsApp($user->phone, $message);
            MessageSender::sendSms($user->phone, $message);*/


            return response()->json([
                'ok' => true,
                'message' => 'Compte créé. Vérifiez votre email ou téléphone pour le code.',
                'user' => $user,
            ], 201);

        } catch (Throwable $e) {
            Log::error('Register error: '.$e->getMessage());
            return response()->json(['error' => 'server_error'], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/api/v1/resend-code",
     *     summary="Renvoyer le code de vérification",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Nouveau code envoyé",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Nouveau code envoyé"),
     *             @OA\Property(property="expires_in", type="integer", example=1, description="Durée de validité du code en minutes")
     *         )
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Trop de requêtes",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="too_many_requests")
     *          )
     *      ),
     *     @OA\Response(response=404, description="Utilisateur introuvable")
     * )
     */
    public function resend(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Si cet email existe, un code a été envoyé.'
            ]);
        }

        // Rate limit per user
        $key = "resend_verif_{$user->id}";
        if (Cache::has($key)) {
            AuditLogger::event(
                'resend_verification_failed_rate_limit',
                'UserVerification',
                $user->id,
                [],
                ['email' => $user->email]
            );
            return response()->json(['error' => 'too_many_requests', 'message' => "trop de demandes"], 429);
        }

        // Invalidate previous codes
        UserVerification::where('user_id', $user->id)
            ->where('type', 'email_verification')
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Create new code
        $code = UserVerification::generateCode();
        UserVerification::create([
            'user_id' => $user->id,
            'code' => hash('sha256', $code),
            'attempts' => 0,
            'expires_at' => now()->addMinute(5),
            'type' => 'email_verification'
        ]);

        AuditLogger::event(
            'resend_verification',
            'UserVerification',
            $user->id,
            [],
            ['code' => hash('sha256', $code)]
        );

        Mail::to($user->email)->send(new VerificationCodeMail($user, $code, 1));
        //MessageSender::sendWhatsApp($user->phone, "Votre code de vérification: {$code}");
        //MessageSender::sendSms($user->phone, "Votre code de vérification: {$code}");

        // Cooldown 60 sec
        Cache::put($key, true, 60);

        return response()->json(['ok' => true, 'message' => 'Nouveau code envoyé']);
    }
    /**
     * @OA\Post(
     *     path="/api/v1/verify-code",
     *     summary="Vérifier le code et activer le compte",
     *     description="Vérifie le code de confirmation et connecte automatiquement l'utilisateur.",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","code"},
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="code", type="string", example="A9XK3P")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Compte vérifié avec succès",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="token", type="string", example="jwt.token.here"),
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="expires_in", type="integer", example=15, description="Durée de validité restante du code en minutes")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Code invalide ou expiré ou encore Aucune vérification trouvée",
     *         @OA\JsonContent(
     *              @OA\Property(property="error", type="string", example="invalid_code"),
     *              @OA\Property(property="remaining_attempts", type="integer", example=5)
     *          )
     *      ),
     *     @OA\Response(
     *         response=429,
     *         description="Trop de tentatives",
     *         @OA\JsonContent(
     *              @OA\Property(property="error", type="string", example="invalid_code"),
     *              @OA\Property(property="remaining_attempts", type="integer", example=5)
     *          )
     *      ),
     * )
     */
    public function verify(VerifyCodeRequest $request)
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();
        if (is_null($user)){
            AuditLogger::event(
                'verification_failed',
                'User',
                null,
                [],
                ['raison' => "email_invalided"]
            );
            return response()->json(['error' => 'invalid_code'], 422);
        }

        $verification = UserVerification::where('user_id', $user->id)
            ->where('type', 'email_verification')
            ->whereNull('used_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $verification) {
            AuditLogger::event(
                'verification_failed',
                'UserVerification',
                $user->id,
                [],
                ['entered_code' => $data['code']]
            );
            return response()->json(['error' => 'invalid_code'], 422);
        }

        if ($verification->isExpired()) {
            return response()->json(['error' => 'code_expired'], 422);
        }

        if (! hash_equals($verification->code, hash('sha256', Str::upper($data['code'])))) {
            $verification->attempts++;
            $verification->save();

            if ($verification->attempts >= 5) {
                $newCode = UserVerification::generateCode();
                $verification->used_at = now();
                $verification->save();

                UserVerification::create([
                    'user_id' => $user->id,
                    'code' => hash('sha256', $newCode),
                    'attempts' => 0,
                    'expires_at' => now()->addMinute(5),
                    'type' => 'email_verification'
                ]);

                AuditLogger::event(
                    'verification_too_many_attempts',
                    'UserVerification',
                    $user->id,
                    ['attempts' => $verification->attempts],
                    ['new_code' => hash('sha256', $newCode)]
                );

                Mail::to($user->email)->send(new VerificationCodeMail($user, $newCode, 1));

                return response()->json(['error' => 'too_many_attempts_new_code_sent'], 429);
            }

            return response()->json([
                'error' => 'invalid_code',
                'remaining_attempts' => 5 - $verification->attempts
            ], 422);
        }

        DB::transaction(function () use (&$user, &$verification){
            // ✔️ Success
            $verification->markUsed();

            $user->is_verified = true;
            $user->save();
        });


        AuditLogger::event(
            'verification_success',
            'User',
            $user->id,
            [],
            ['verified_at' => now()]
        );

        DB::afterCommit(function () use (&$user) {
            Mail::to($user->email)->send(new WelcomeMail($user));
        });

        // Auto-login
        $token = JWTAuth::fromUser($user);

        // Si le frontend passe visitor_uuid
        if ($visitorUuid = request('visitor_uuid')) {
            $visitor = Visitor::where('uuid', $visitorUuid)
                ->where('site_id', $request->site_id)
                ->first();

            if ($visitor) {
                $this->transformVisitorToUser($visitor, $user);
            }
        }

        // Attach user to site if provided
        $this->attachUserToSiteIfNeeded($user, $request->site_id);

        return response()->json([
            'message' => 'Compte vérifié avec succès.',
            'token' => $token,
            'user' => $user,
        ]);
    }
    /**
     * @OA\Post(
     *     path="/api/v1/login",
     *     summary="Connexion utilisateur",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="Secret123!")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Connexion réussie",
     *         @OA\JsonContent(
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Identifiants invalides"),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Compte non vérifié",
     *         @OA\JsonContent(
     *              @OA\Property(property="error", type="string", example="account_not_verified"),
     *              @OA\Property(property="message", type="string", example="Votre compte n’est pas encore vérifié...")
     *          )
     *      )
     * )
     */
    public function login(Request $request)
    {
        $payload = $request->validate([
            'email' => 'required_without_all:google_token,facebook_token|email',
            'password' => 'required_without_all:google_token,facebook_token',
            'site_id' => 'nullable|uuid|exists:sites,id',
            'google_token' => 'nullable|string',
            'facebook_token' => 'nullable|string',
        ]);

        // 🔵 GOOGLE FLOW
        if(!empty($payload['google_token'])){
            $client = new GoogleClient([
                'client_id' => env('GOOGLE_CLIENT_ID'),
            ]);

            $googleData = $client->verifyIdToken($request->google_token);

            if (!$googleData) {
                return response()->json(['error' => 'Invalid Google token'], 401);
            }

            // Récupération email + prénom/nom
            $payload['email'] = $googleData['email'];
            $payload['firstname'] = $googleData['given_name'] ?? '';
            $payload['lastname'] = $googleData['family_name'] ?? '';
        }

        // 🔵 FACEBOOK FLOW
        if(!empty($payload['facebook_token'])) {
            $fb = new Facebook([
                'app_id' => env('FACEBOOK_APP_ID'),
                'app_secret' => env('FACEBOOK_APP_SECRET'),
                'default_graph_version' => 'v17.0',
            ]);

            try {
                $response = $fb->get('/me?fields=id,first_name,last_name,email', $payload['facebook_token']);
                $fbData = $response->getGraphUser();

                $payload['email'] = $fbData->getEmail();
                $payload['firstname'] = $fbData->getFirstName() ?? '';
                $payload['lastname'] = $fbData->getLastName() ?? '';
            } catch (FacebookResponseException $e) {
                return response()->json(['error' => 'Invalid Facebook token'], 401);
            } catch (FacebookSDKException $e) {
                return response()->json(['error' => 'Facebook SDK error'], 500);
            }
        }

        /**
         * @var User $user
         */
        $user = User::where('email', $payload['email'])->whereNull('deleted_at')->first();

        if (! $user) {
            AuditLogger::event(
                'login_failed',
                'User',
                null,
                [],
                ['email' => $request->email, 'reason' => 'user_not_found']
            );
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        if (! $user->is_verified) {
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

        if(!empty($payload['google_token']) || !empty($payload['facebook_token'])){
            // Générer le token JWT
            $token = JWTAuth::fromUser($user);
        }else{
            $credentials = $request->only('email', 'password');

            if (! $token = JWTAuth::attempt($credentials)) {
                return response()->json(['error' => 'invalid_credentials'], 401);
            }
        }

        AuditLogger::event(
            'login',
            'User',
            Auth::id(),
            [],
            ['logged_in_at' => now()]
        );

        if ($visitorUuid = request('visitor_uuid')) {
            $visitor = Visitor::where('uuid', $visitorUuid)
                ->where('site_id', $request->site_id)
                ->first();

            if ($visitor) {
                $this->transformVisitorToUser($visitor, $user);
            }
        }

        $this->attachUserToSiteIfNeeded($user, $request->site_id);

        return response()->json([
            'token' => $token,
            'user' => !empty($payload['google_token']) || !empty($payload['facebook_token']) ? $user : auth()->user(),
        ]);
    }
    /**
     * @OA\Post(
     *     path="/api/v1/refresh-token",
     *     summary="Rafraîchir le token JWT",
     *     tags={"Auth"},
     *     security={ {"bearerAuth": {}} },
     *
     *     @OA\SecurityScheme(
     *         securityScheme="bearerAuth",
     *         type="http",
     *         scheme="bearer",
     *         bearerFormat="JWT"
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Token rafraîchi",
     *         @OA\JsonContent(
     *             @OA\Property(property="token", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *          response=401,
     *          description="Token invalide, absent ou expiré. L'erreur retournée dans le JSON peut être : token_missing, token_invalid ou token_expired",
     *          @OA\JsonContent(
     *              @OA\Property(property="error", type="string", example="token_missing")
     *          )
     *      ),
     * )
     */
    public function refreshToken(Request $request)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'token_missing'], 401);
        }

        try {
            // Assigne explicitement le token
            $newToken = JWTAuth::setToken($token)->refresh();
            AuditLogger::event(
                'refresh_token_success',
                'User',
                Auth::id(),
                [],
                ['new_token' => $newToken]
            );
            return response()->json(['token' => $newToken]);
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            AuditLogger::event(
                'refresh_token_failed',
                'User',
                null,
                [],
                ['reason' => 'token_expired']
            );
            return response()->json(['error' => 'token_expired'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            AuditLogger::event(
                'refresh_token_failed',
                'User',
                null,
                [],
                ['reason' => 'token_invalid']
            );
            return response()->json(['error' => 'token_invalid'], 401);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            AuditLogger::event(
                'refresh_token_failed',
                'User',
                null,
                [],
                ['reason' => 'token_absent']
            );
            return response()->json(['error' => 'token_absent'], 401);
        }
    }
    /**
     * @OA\Post(
     *     path="/api/v1/logout",
     *     summary="Déconnexion utilisateur",
     *     tags={"Auth"},
     *     security={ {"bearerAuth": {}} },
     *     @OA\SecurityScheme(
     *          securityScheme="bearerAuth",
     *          type="http",
     *          scheme="bearer",
     *          bearerFormat="JWT"
     *      ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Déconnexion réussie",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Déconnexion réussie.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Erreur serveur lors de la déconnexion",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Impossible de se déconnecter.")
     *         )
     *     )
     * )
     */
    public function logout(Request $request){
        try {
            // Récupère le token courant et l'invalide
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'message' => 'Déconnexion réussie.'
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'error' => 'Impossible de se déconnecter.'
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/api/v1/forgot-password",
     *     summary="Demander un code de réinitialisation de mot de passe",
     *     description="Permet à un utilisateur de demander un code de réinitialisation de mot de passe. Toujours retourne un message générique pour éviter l'énumération d'emails existants.",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Code envoyé si l'email existe (valide 15 minutes)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Si cet email existe, un code a été envoyé."),
     *             @OA\Property(property="expires_in", type="integer", example=15, description="Durée de validité du code en minutes")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=429,
     *         description="Trop de requêtes : un code a été demandé récemment",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Veuillez patienter avant de demander un nouveau code.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation de l'email échouée",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The email field is required.")
     *         )
     *     )
     * )
     */
    public function sendPasswordResetCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->whereNull('deleted_at')->first();

        // Toujours répondre OK (anti-énumération)
        if (! $user) {
            return response()->json([
                'message' => 'Si cet email existe, un code a été envoyé.',
                'expires_in' => 15
            ]);
        }

        $key = "password_reset_{$user->id}";
        if (Cache::has($key)) {
            return response()->json([
                'message' => 'Veuillez patienter avant de demander un nouveau code.'
            ], 429);
        }

        // Invalider anciens codes
        UserVerification::where('user_id', $user->id)
            ->where('type', 'password_reset')
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = UserVerification::generateCode();

        UserVerification::create([
            'user_id' => $user->id,
            'code' => hash('sha256', $code),
            'type' => 'password_reset',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::to($user->email)->send(
            new ResetPasswordCodeMail($user, $code)
        );

        Cache::put($key, true, 60);

        return response()->json([
            'message' => 'Si cet email existe, un code a été envoyé.',
            'expires_in' => 15
        ]);
    }
    /**
     * @OA\Post(
     *     path="/api/v1/reset-password",
     *     summary="Réinitialiser le mot de passe avec un code",
     *     description="Permet à un utilisateur de réinitialiser son mot de passe en utilisant un code envoyé par email.
     *                   Le code est valide 15 minutes.",
     *     tags={"Auth"},
     *     security={},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","code","password","password_confirmation"},
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="code", type="string", example="A9XK3P", description="Code reçu par email (valide 15 minutes)"),
     *             @OA\Property(property="password", type="string", format="password", example="NewPassword123!", description="Mot de passe choisi par l’utilisateur"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="NewPassword123!")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Mot de passe réinitialisé avec succès",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Mot de passe réinitialisé avec succès")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Code invalide, expiré ou email inconnu",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Code invalide ou expiré")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=429,
     *         description="Trop de tentatives pour ce code",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Code bloqué. Veuillez demander un nouveau code.")
     *         )
     *     )
     * )
     */
    public function resetPasswordWithCode(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', /*'min:8'*/],
        ]);

        $user = User::where('email', $request->email)->whereNull('deleted_at')->first();

        if (! $user) {
            return response()->json([
                'message' => 'Code invalide ou expiré'
            ], 422);
        }

        $verification = UserVerification::where('user_id', $user->id)
            ->where('type', 'password_reset')
            ->where('code', hash('sha256', Str::upper($request->code)))
            ->whereNull('used_at')
            ->first();

        if (! $verification || $verification->isExpired()) {
            return response()->json([
                'message' => 'Code invalide ou expiré'
            ], 422);
        }

        if (!hash_equals($verification->code, hash('sha256', Str::upper($request->code)))){

            $verification->increment('attempts');

            if ($verification->attempts >= 5) {
                $verification->markUsed();
                return response()->json([
                    'message' => 'Code bloqué. Veuillez demander un nouveau code.'
                ], 429);
            }

            return response()->json([
                'message' => 'Code invalide ou expiré'
            ], 422);
        }

        DB::transaction(function () use ($verification, $user, $request) {
            $user->password = Hash::make($request->password);
            $user->save();

            $verification->markUsed();
        });

        if ($token = JWTAuth::getToken()) {
            JWTAuth::invalidate($token);
        }

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès'
        ]);
    }
    protected function errorResponse(
        string $message,
        string $errorCode,
        int $status = 400
    ) {
        return response()->json([
            'message'    => $message,
            'error_code' => $errorCode,
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

    public function redirectToGoogle(Request $request)
    {
        $siteId = $request->query('site_id');

        session(['site_id' => $siteId]);

        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }
    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $siteId = session('site_id');

        $user = User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'firstname' => $googleUser->user['given_name'] ?? '',
                'lastname' => $googleUser->user['family_name'] ?? '',
                'password' => Hash::make(Str::random(16)),
                'is_verified' => true,
                'role_id' => Role::where('name','visitor')->first()->id,
            ]
        );

        if ($siteId) {
            $site = Site::findOrFail($siteId);
            $site->users()->syncWithoutDetaching([$user->id]);
            $this->attachUserToSiteIfNeeded($user, $siteId);
        }

        $token = $user->createToken('widget')->plainTextToken;

        return response()->view('oauth-callback', [
            'token' => $token,
            'user' => $user
        ]);
    }

    protected function transformVisitorToUser(Visitor $visitor, User $user)
    {
        // 1️⃣ Associer le visitor à l'user
        $visitor->user_id = $user->id;
        $visitor->save();

        // 2️⃣ Migrer les conversations du visitor vers l'user
        Conversation::where('visitor_id', $visitor->id)
            ->update(['user_id' => $user->id]);

        // 3️⃣ Optionnel: mettre à jour toutes les messages si nécessaire
        Message::whereIn('conversation_id', function($q) use ($visitor) {
            $q->select('id')
                ->from('conversations')
                ->where('visitor_id', $visitor->id);
        })->update(['user_id' => $user->id]);

        // 4️⃣ Attacher l'utilisateur au site si nécessaire
        //$this->attachUserToSiteIfNeeded($user, $visitor->site_id);
    }
}
