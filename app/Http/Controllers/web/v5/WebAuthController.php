<?php

namespace App\Http\Controllers\web\v5;

use App\Http\Controllers\Controller;
use App\Traits\JwtAuth;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * WebAuthController
 *
 * Gère les pages d'authentification côté Blade.
 * Le vrai travail d'auth est fait par l'API JWT (AuthController API).
 * Ce controller sert uniquement à :
 *  1. Afficher les vues Blade
 *  2. Synchroniser le token JWT entre localStorage et session Laravel
 *     (pour que JwtAuthMiddleware puisse le lire sur les requêtes Blade)
 */
class WebAuthController extends Controller
{
    use JwtAuth;

    // ─── Pages ───────────────────────────────────────────────────────────────

    public function showLogin(Request $request)
    {
        // Déjà connecté → redirect app
        if ($request->session()->has('jwt_token')) {
            return redirect()->intended('/app');
        }

        return view('auth.login', [
            'message' => $request->get('message') ?? session('error'),
        ]);
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function showVerification(Request $request)
    {
        $email = $request->get('email') ?? session('verify_email');

        if (!$email) {
            return redirect()->route('auth.register')
                ->with('error', 'Email manquant pour la vérification.');
        }

        return view('auth.verify', compact('email'));
    }

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function showResetPassword(Request $request)
    {
        $email = $request->get('email') ?? session('reset_email');

        if (!$email) {
            return redirect()->route('auth.forgot-password')
                ->with('error', 'Email manquant.');
        }

        return view('auth.reset-password', compact('email'));
    }

    // ─── Session sync ─────────────────────────────────────────────────────────

    /**
     * POST /auth/sync-token
     * Appelé par elchat-api.js après chaque connexion/refresh.
     * Stocke le token JWT en session Laravel pour que
     * JwtAuthMiddleware puisse l'utiliser sur les requêtes Blade.
     */
    public function syncToken(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);
        $request->session()->put('jwt_token', $request->token);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /auth/clear-session
     * Appelé par elchat-api.js lors du logout.
     * Efface la session Laravel.
     */
    public function clearSession(Request $request): JsonResponse
    {
        $request->session()->forget('jwt_token');
        $request->session()->flush();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /auth/logout
     * Déconnexion depuis une page Blade (sans JS).
     */
    public function logout(Request $request)
    {
        $request->session()->flush();
        return redirect()->route('auth.login')
            ->with('success', 'Vous avez été déconnecté.');
    }
}
