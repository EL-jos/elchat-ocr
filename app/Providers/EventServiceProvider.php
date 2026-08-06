<?php

namespace App\Providers;

use App\Events\ModuleActivated;
use App\Events\ModuleDeactivated;
use App\Listeners\GrantModulePermissions;
use App\Listeners\RevokeModulePermissions;
use App\Listeners\SendModuleActivationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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
    ];
}
