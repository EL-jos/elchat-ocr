<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Vérifie et authentifie l'utilisateur via le token
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'error' => 'user_not_found'
                ], 401);
            }

        } catch (TokenExpiredException $e) {
            return response()->json([
                'error' => 'token_expired'
            ], 401);

        } catch (TokenInvalidException $e) {
            return response()->json([
                'error' => 'token_invalid'
            ], 401);

        } catch (JWTException $e) {
            return response()->json([
                'error' => 'token_not_provided'
            ], 401);
        }

        return $next($request);
    }
}
