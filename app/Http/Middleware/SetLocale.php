<?php

namespace App\Http\Middleware;

use App\Support\SiteLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');
        $routeName = $request->route()?->getName();
        $frenchRouteNames = [
            'home.page', 'about.page', 'services.page', 'service.single',
            'abonnements.page', 'faqs.page', 'contact.page', 'contact.send',
            'politique_de_confidentialite.page', 'cgu.page', 'ml.page',
        ];
        $locale = SiteLocale::isSupported($routeLocale)
            ? SiteLocale::normalize($routeLocale)
            : (in_array($routeName, $frenchRouteNames, true) ? SiteLocale::DEFAULT : SiteLocale::detect($request));

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->setCookie(cookie('elchat_locale', $locale, 60 * 24 * 365));

        return $response;
    }
}
