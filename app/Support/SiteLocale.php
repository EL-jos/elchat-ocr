<?php

namespace App\Support;

use Illuminate\Http\Request;

class SiteLocale
{
    public const DEFAULT = 'fr';

    public const SUPPORTED = ['fr', 'en', 'pt', 'es'];

    private const FRENCH_ROUTES = [
        'home' => 'home.page',
        'about' => 'about.page',
        'services' => 'services.page',
        'pricing' => 'abonnements.page',
        'faqs' => 'faqs.page',
        'contact' => 'contact.page',
        'privacy' => 'politique_de_confidentialite.page',
        'terms' => 'cgu.page',
        'legal' => 'ml.page',
        'service' => 'service.single',
    ];

    private const LOCALIZED_ROUTES = [
        'home' => 'localized.home.page',
        'about' => 'localized.about.page',
        'services' => 'localized.services.page',
        'pricing' => 'localized.abonnements.page',
        'faqs' => 'localized.faqs.page',
        'contact' => 'localized.contact.page',
        'privacy' => 'localized.privacy.page',
        'terms' => 'localized.terms.page',
        'legal' => 'localized.legal.page',
        'service' => 'localized.service.single',
    ];

    private const ROUTE_TO_PAGE = [
        'home.page' => 'home',
        'about.page' => 'about',
        'services.page' => 'services',
        'abonnements.page' => 'pricing',
        'faqs.page' => 'faqs',
        'contact.page' => 'contact',
        'politique_de_confidentialite.page' => 'privacy',
        'cgu.page' => 'terms',
        'ml.page' => 'legal',
        'service.single' => 'service',
        'localized.home.page' => 'home',
        'localized.about.page' => 'about',
        'localized.services.page' => 'services',
        'localized.abonnements.page' => 'pricing',
        'localized.faqs.page' => 'faqs',
        'localized.contact.page' => 'contact',
        'localized.privacy.page' => 'privacy',
        'localized.terms.page' => 'terms',
        'localized.legal.page' => 'legal',
        'localized.service.single' => 'service',
    ];

    public static function languages(): array
    {
        return [
            'fr' => ['label' => 'Français', 'native' => 'Français', 'flag' => 'fr', 'hreflang' => 'fr-FR'],
            'en' => ['label' => 'English', 'native' => 'English', 'flag' => 'gb', 'hreflang' => 'en'],
            'pt' => ['label' => 'Português', 'native' => 'Português', 'flag' => 'pt', 'hreflang' => 'pt'],
            'es' => ['label' => 'Español', 'native' => 'Español', 'flag' => 'es', 'hreflang' => 'es'],
        ];
    }

    public static function isSupported(?string $locale): bool
    {
        return is_string($locale) && in_array(strtolower($locale), self::SUPPORTED, true);
    }

    public static function normalize(?string $locale): string
    {
        $locale = strtolower((string) $locale);

        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    public static function detect(Request $request): string
    {
        $cookieLocale = $request->cookie('elchat_locale');
        if (self::isSupported($cookieLocale)) {
            return self::normalize($cookieLocale);
        }

        foreach ($request->getLanguages() as $language) {
            $language = strtolower(str_replace('_', '-', $language));
            $baseLanguage = explode('-', $language)[0];

            if (self::isSupported($baseLanguage)) {
                return $baseLanguage;
            }
        }

        return self::DEFAULT;
    }

    public static function currentPage(): array
    {
        $route = request()->route();
        $routeName = $route?->getName();
        $page = self::ROUTE_TO_PAGE[$routeName] ?? 'home';

        return [
            'key' => $page,
            'slug' => $route?->parameter('slug'),
        ];
    }

    public static function route(string $routeName, array $parameters = []): string
    {
        $page = self::ROUTE_TO_PAGE[$routeName] ?? null;

        if (!$page) {
            return route($routeName, $parameters);
        }

        return self::urlForPage($page, app()->getLocale(), $parameters['slug'] ?? null);
    }

    public static function urlForPage(string $page, ?string $locale = null, ?string $slug = null): string
    {
        $locale = self::normalize($locale ?: app()->getLocale());
        $routeName = $locale === self::DEFAULT
            ? (self::FRENCH_ROUTES[$page] ?? self::FRENCH_ROUTES['home'])
            : (self::LOCALIZED_ROUTES[$page] ?? self::LOCALIZED_ROUTES['home']);

        $parameters = $locale === self::DEFAULT ? [] : ['locale' => $locale];
        if ($page === 'service' && $slug) {
            $parameters['slug'] = $slug;
        }

        return route($routeName, $parameters);
    }
}
