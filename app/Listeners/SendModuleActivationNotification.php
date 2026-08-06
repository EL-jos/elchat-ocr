<?php

namespace App\Listeners;

use App\Events\ModuleActivated;
use Illuminate\Support\Facades\Log;

class SendModuleActivationNotification
{
    public function handle(ModuleActivated $event): void
    {
        // TODO : brancher un Mailable dédié si souhaité (ex: emails/module-activated.blade.php)
        Log::info('SendModuleActivationNotification: module activated', [
            'account_id' => $event->subscription->account_id,
            'module'     => $event->item->module->slug,
        ]);
    }
}
