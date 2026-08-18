<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthMiddleware
{
    /**
     * Résoudre l'utilisateur depuis le JWT Bearer token.
     *
     * Fonctionne pour :
     * - Requêtes Blade (token stocké en session ou cookie)
     * - Requêtes API/AJAX (Authorization: Bearer <token>)
     *
     * Après ce middleware, on peut utiliser :
     *   $request->jwt_user    → User model
     *   $request->jwt_account → Account model (ownedAccount)
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Chercher le token dans : Bearer header > session > cookie
        $token = $this->resolveToken($request);

        if (!$token) {
            return $this->unauthorized($request, 'Token manquant.');
        }

        try {
            JWTAuth::setToken($token);
            $user = JWTAuth::authenticate();

            if (!$user) {
                return $this->unauthorized($request, 'Utilisateur introuvable.');
            }

            // Charger l'account associé (relation ownedAccount)
            $account = $user->ownedAccount ?? null;

            // Injecter dans la requête pour tous les controllers
            $request->merge([
                'jwt_user'    => $user,
                'jwt_account' => $account,
            ]);

            // Compatibilité Auth facade (optionnel)
            auth()->setUser($user);

            return $next($request);

        } catch (TokenExpiredException $e) {
            // Tenter de rafraîchir automatiquement
            try {
                $newToken = JWTAuth::refresh($token);
                JWTAuth::setToken($newToken);
                $user    = JWTAuth::authenticate();
                $account = $user->ownedAccount ?? null;

                $request->merge([
                    'jwt_user'    => $user,
                    'jwt_account' => $account,
                ]);

                auth()->setUser($user);

                $response = $next($request);

                // Retourner le nouveau token dans le header
                $response->headers->set('X-New-Token', $newToken);

                // Mettre à jour la session si elle existe
                if ($request->hasSession()) {
                    $request->session()->put('jwt_token', $newToken);
                }

                return $response;

            } catch (JWTException $refreshException) {
                return $this->unauthorized($request, 'Session expirée. Veuillez vous reconnecter.', 'token_expired');
            }

        } catch (TokenInvalidException $e) {
            return $this->unauthorized($request, 'Token invalide.', 'token_invalid');

        } catch (JWTException $e) {
            return $this->unauthorized($request, 'Erreur d\'authentification.', 'jwt_error');
        }
    }

    /**
     * Résoudre le token depuis plusieurs sources.
     */
    private function resolveToken(Request $request): ?string
    {
        // 1. Authorization: Bearer <token>
        if ($token = $request->bearerToken()) {
            return $token;
        }

        // 2. Session (pages Blade)
        if ($request->hasSession() && $token = $request->session()->get('jwt_token')) {
            return $token;
        }

        // 3. Cookie httpOnly (fallback)
        if ($token = $request->cookie('jwt_token')) {
            return $token;
        }

        return null;
    }

    /**
     * Réponse non autorisée — JSON pour API, redirect pour Blade.
     */
    private function unauthorized(Request $request, string $message, string $code = 'unauthenticated'): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error'   => $code,
                'message' => $message,
            ], 401);
        }

        // Sauvegarder l'URL demandée pour redirect après login
        if ($request->hasSession()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('auth.login')
            ->with('error', 'Votre session a expiré. Veuillez vous reconnecter.');
    }
}
