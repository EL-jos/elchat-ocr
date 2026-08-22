<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Site;

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
            'http://localhost:4200', // Pour le dev
        ]));

        // Visitor Intelligence can run from the tenant page before the iframe
        // opens. Allow only the exact origin configured on the route's site.
        $site = $request->route('site');
        if (is_string($site)) {
            $site = Site::query()->find($site);
        }
        if ($site instanceof Site) {
            $parts = parse_url((string) $site->url);
            if (isset($parts['scheme'], $parts['host'])) {
                $allowedOrigins[] = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        $origin = $request->header('Origin');
        if (!$origin && $request->header('Referer')) {
            $parts = parse_url($request->header('Referer'));
            if (isset($parts['scheme'], $parts['host'])) {
                $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        if (!$origin || !in_array(rtrim($origin, '/'), array_map(fn ($item) => rtrim($item, '/'), $allowedOrigins), true)) {
            Log::warning('Visitor Intelligence widget request rejected due to invalid origin.', [
                'site_id' => $site instanceof Site ? $site->id : (string) $request->route('site'),
                'origin' => $origin,
                'path' => $request->path(),
            ]);
            abort(403, 'Invalid origin');
        }

        return $next($request);
    }
}
