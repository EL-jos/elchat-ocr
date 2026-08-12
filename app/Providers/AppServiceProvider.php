<?php

namespace App\Providers;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\EmailProviderFactory;
use App\Domain\Email\Providers\PostmarkEmailProvider;
use App\Domain\Email\Providers\SesEmailProvider;
use App\Payment\Adapters\PaypalCouponAdapter;
use App\Payment\Gateways\PaypalPaymentGateway;
use App\Payment\PaymentGatewayFactory;
use App\Services\payment\CouponService;
use App\Services\payment\ModuleCatalogService;
use App\Services\payment\PricingCalculator;
use App\Services\payment\SubscriptionOrchestrator;
use Aws\Ses\SesClient;
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

        $this->app->singleton(SesClient::class, fn () => new SesClient([
            'version' => 'latest',
            'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION', 'eu-west-1')),
            'credentials' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY')],
        ]));

        $this->app->bind(EmailProviderInterface::class, fn () => EmailProviderFactory::make(config('mail-providers.default')));
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
