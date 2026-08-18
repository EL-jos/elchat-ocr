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
        $allowedOrigins = array_values(array_filter([
            env('WIDGET_ORIGIN'),
            'https://elchat.io',
            'http://localhost:4200' // Pour le dev
        ]));

        $origin = $request->header('Origin');
        if (!$origin && $request->header('Referer')) {
            $parts = parse_url($request->header('Referer'));
            if (isset($parts['scheme'], $parts['host'])) {
                $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        if (!$origin || !in_array(rtrim($origin, '/'), array_map(fn ($item) => rtrim($item, '/'), $allowedOrigins), true)) {
            abort(403, 'Invalid origin');
        }

        return $next($request);
    }
}
