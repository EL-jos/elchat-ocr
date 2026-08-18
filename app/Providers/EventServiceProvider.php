<?php

namespace App\Providers;

use App\Events\ModuleActivated;
use App\Events\ModuleDeactivated;
use App\Listeners\GrantModulePermissions;
use App\Listeners\HandleProactiveAnalyticsEvent;
use App\Listeners\RevokeModulePermissions;
use App\Listeners\SendModuleActivationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\AnalyticsEventRecorded;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        ModuleActivated::class => [
            GrantModulePermissions::class,
            SendModuleActivationNotification::class,
        ],

        ModuleDeactivated::class => [
            RevokeModulePermissions::class,
        ],

        // Le moteur proactif consomme les mêmes événements que l’Event
        // Intelligence existante. Le listener est asynchrone et isolé sur la
        // queue proactive : aucune requête de chat ne dépend de son exécution.
        AnalyticsEventRecorded::class => [
            HandleProactiveAnalyticsEvent::class,
        ],
    ];
}
