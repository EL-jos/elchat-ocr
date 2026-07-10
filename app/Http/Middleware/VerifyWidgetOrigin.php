<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWidgetOrigin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        $allowedOrigins = [
            env('WIDGET_ORIGIN'),
            'https://elchat-widget.promogifts.ma',
            'http://localhost:4200' // Pour le dev
        ];

        $origin = $request->header('Origin') ?: $request->header('Referer');

        if ($origin && !in_array($origin, $allowedOrigins)) {
            abort(403, 'Invalid origin');
        }

        return $next($request);
    }
}
