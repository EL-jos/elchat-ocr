<?php

namespace App\Domain\Sales;

use App\Enums\AnalyticsEventType;
use App\Models\Conversation;
use App\Models\Sales\Prospect;
use App\Services\analytics\AnalyticsEventService;

/** Synchronisation idempotente d'un prospect local vers le CRM choisi. */
class ProspectCrmSyncService
{
    public function __construct(
        private readonly ProspectingCrmGateway $crm,
        private readonly AnalyticsEventService $analytics,
    ) {}

    public function sync(Prospect $prospect, ?string $connectorSlug, Conversation $conversation): void
    {
        if (in_array($prospect->crm_sync_status, ['created', 'duplicate', 'linked'], true)) {
            return;
        }

        if (! $connectorSlug || ! $this->crm->isAvailable($prospect->site, $connectorSlug)) {
            $prospect->update(['crm_sync_status' => 'pending_crm', 'crm_sync_error' => 'Aucun CRM de destination connecté ou sélectionné.']);

            return;
        }
        if (blank($prospect->email) && blank($prospect->phone)) {
            $prospect->update([
                'crm_sync_status' => 'pending_contact_info',
                'crm_sync_error' => 'Un numéro de téléphone ou une adresse email est nécessaire pour créer le contact CRM.',
            ]);

            return;
        }

        // Une adresse email permet de dédupliquer avant création. En son
        // absence, la création reste possible avec un téléphone ; le CRM
        // doit alors retourner une erreur s'il impose une contrainte propre.
        if (filled($prospect->email)) {
            $check = $this->crm->find($prospect->site, $conversation, $prospect->email, $connectorSlug);
            $this->analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CRM_CHECK_COMPLETED, [
                'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
            ], ['connector_slug' => $connectorSlug, 'success' => $check->success, 'exists' => $check->data['exists'] ?? null], async: true);

            if ($check->success && ($check->data['exists'] ?? false)) {
                $prospect->update(['crm_sync_status' => 'duplicate', 'crm_ref' => ['connector_slug' => $connectorSlug, 'existing' => true], 'crm_sync_error' => null]);
                $this->analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CRM_UPDATED, [
                    'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
                ], ['connector_slug' => $connectorSlug, 'action' => 'existing_contact_linked'], async: true);

                return;
            }
            if (! $check->success && $check->errorCode !== 'not_found') {
                $prospect->update(['crm_sync_status' => 'failed', 'crm_sync_error' => $check->errorMessage]);

                return;
            }
        }

        $created = $this->crm->create($prospect->site, $conversation, $prospect->toArray(), $connectorSlug);
        if (! $created->success) {
            $prospect->update(['crm_sync_status' => 'failed', 'crm_sync_error' => $created->errorMessage]);

            return;
        }

        $prospect->update([
            'crm_sync_status' => 'created',
            'crm_ref' => ['connector_slug' => $connectorSlug, 'external_id' => $created->data['contact_id'] ?? null],
            'crm_sync_error' => null,
        ]);
        $this->analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CRM_CREATED, [
            'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
        ], ['connector_slug' => $connectorSlug], async: true);
    }
}
