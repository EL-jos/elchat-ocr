<?php

namespace App\Listeners;

use App\Events\ModuleActivated;
use Illuminate\Support\Facades\Log;

/**
 * Active les permissions/features du tenant pour le module nouvellement activé.
 * Point d'intégration avec le reste de la plateforme ELChat (RAG, connecteurs,
 * workflow engine, agents...) — à brancher sur votre système de permissions existant.
 */
class GrantModulePermissions
{
    public function handle(ModuleActivated $event): void
    {
        $account = $event->subscription->account;
        $module  = $event->item->module;

        // TODO : intégrer avec le système de permissions/features réel d'ELChat
        // Exemple : FeatureFlag::enable($account, $module->slug);

        Log::info('GrantModulePermissions: module access granted', [
            'account_id' => $account->id,
            'module'     => $module->slug,
            'tier'       => $event->item->moduleTier?->slug,
        ]);
    }
}
