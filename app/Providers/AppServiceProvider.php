<?php

namespace App\Providers;

use App\Payment\Adapters\PaypalCouponAdapter;
use App\Payment\Gateways\PaypalPaymentGateway;
use App\Payment\PaymentGatewayFactory;
use App\Services\payment\CouponService;
use App\Services\payment\ModuleCatalogService;
use App\Services\payment\PricingCalculator;
use App\Services\payment\SubscriptionOrchestrator;
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
        $this->app->singleton(PaypalPaymentGateway::class);
        $this->app->singleton(PaymentGatewayFactory::class);
        $this->app->singleton(PaypalCouponAdapter::class);
        $this->app->singleton(PricingCalculator::class);
        $this->app->singleton(CouponService::class);
        $this->app->singleton(ModuleCatalogService::class);
        $this->app->singleton(SubscriptionOrchestrator::class);
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
