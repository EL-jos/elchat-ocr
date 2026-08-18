<?php

namespace App\Listeners;

use App\Events\ModuleDeactivated;
use Illuminate\Support\Facades\Log;

/**
 * Coupe l'ACCÈS au module (jamais les données/configurations sous-jacentes,
 * qui sont conservées indéfiniment — voir point produit "jamais de suppression").
 * Déclenché uniquement par le job planifié de finalisation, jamais à la demande
 * de désactivation elle-même (effet différé à la fin du cycle payé).
 */
class RevokeModulePermissions
{
    public function handle(ModuleDeactivated $event): void
    {
        $account = $event->subscription->account;
        $module  = $event->item->module;

        // TODO : intégrer avec le système de permissions/features réel d'ELChat
        // Exemple : FeatureFlag::disable($account, $module->slug);
        // IMPORTANT : ne jamais supprimer les données/configs du module ici.

        Log::info('RevokeModulePermissions: module access revoked (data preserved)', [
            'account_id' => $account->id,
            'module'     => $module->slug,
        ]);
    }
}
