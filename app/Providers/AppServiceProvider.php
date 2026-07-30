<?php

namespace App\Providers;

use App\Services\payment\CurrencyService;
use App\Services\payment\PayPalService;
use App\Services\payment\PayPalSubscriptionService;
use App\Services\payment\StripeService;
use App\Services\payment\SubscriptionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Slack\SlackExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(CurrencyService::class);
        $this->app->singleton(PayPalService::class);
        $this->app->singleton(PayPalSubscriptionService::class, function ($app) {
            return new PayPalSubscriptionService(
                $app->make(PayPalService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            SocialiteWasCalled::class,
            SlackExtendSocialite::class
        );
    }
}
