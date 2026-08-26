<?php

namespace App\Providers;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\EmailProviderFactory;
use App\Domain\Sales\ProspectDiscoveryService;
use App\Domain\Sales\ProspectingLocationResolver;
use App\Domain\Sales\ProspectingSourceRegistry;
use App\Domain\Sales\Sources\AutonomousWebSearchSource;
use App\Domain\Sales\Sources\CrmColdContactSource;
use App\Domain\Sales\Sources\FoursquarePlacesSource;
use App\Domain\Sales\Sources\HerePlacesSource;
use App\Domain\Sales\Sources\OpenStreetMapQueryBuilder;
use App\Domain\Sales\Sources\OpenStreetMapSource;
use App\Domain\Sales\Sources\TomTomPlacesSource;
use App\Domain\Sales\Sources\WebDiscoverySource;
use App\Events\AnalyticsEventRecorded;
use App\Listeners\HandleProactiveAnalyticsEvent;
use App\Models\UnansweredQuestion;
use App\Observers\UnansweredQuestionObserver;
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
        $this->app->singleton(OpenStreetMapQueryBuilder::class);
        $this->app->singleton(ProspectingLocationResolver::class);
        $this->app->singleton(ProspectingSourceRegistry::class, fn ($app) => new ProspectingSourceRegistry([
            $app->make(OpenStreetMapSource::class),
            $app->make(WebDiscoverySource::class),
            $app->make(AutonomousWebSearchSource::class),
            $app->make(FoursquarePlacesSource::class),
            $app->make(HerePlacesSource::class),
            $app->make(TomTomPlacesSource::class),
            $app->make(CrmColdContactSource::class),
        ]));
        $this->app->singleton(ProspectDiscoveryService::class);

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
        UnansweredQuestion::observe(UnansweredQuestionObserver::class);

        Event::listen(
            SocialiteWasCalled::class,
            SlackExtendSocialite::class
        );

        Event::listen(AnalyticsEventRecorded::class, HandleProactiveAnalyticsEvent::class);
    }
}
