<?php

namespace App\Services\analytics;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ResourceEvent;
use App\Models\Site;
use Illuminate\Support\Str;

class ResourceEventLogger
{
    /**
     * Log une impression pour chaque CTA proposé dans une réponse chat.
     * $ctas = tableau brut renvoyé par ChatResponse::ctas (issu de CtaResource::make()).
     */
    public function logCtaImpressions(Site $site, Conversation $conversation, Message $message, array $ctas): void
    {
        foreach ($ctas as $cta) {
            $this->log(
                site: $site,
                conversation: $conversation,
                messageId: $message->id,
                resourceType: 'cta',
                resourceId: $cta['id'],
                eventType: 'impression',
                action: $cta['action'] ?? null,
                label: $cta['label'] ?? null,
                metadata: ['value' => $cta['value'] ?? null, 'style' => $cta['style'] ?? null],
            );
        }
    }

    /**
     * Log une impression pour chaque entity (produit, page, document, image)
     * proposée dans une réponse chat.
     */
    public function logEntityImpressions(Site $site, Conversation $conversation, Message $message, array $entities): void
    {
        foreach ($entities as $entity) {
            $this->log(
                site: $site,
                conversation: $conversation,
                messageId: $message->id,
                resourceType: $entity['type'] ?? 'unknown',
                resourceId: $entity['id'] ?? null,
                eventType: 'impression',
                label: $entity['title'] ?? null,
                metadata: ['url' => $entity['url'] ?? null, 'price' => $entity['price'] ?? null],
            );
        }
    }

    /**
     * Log un clic (appelé depuis l'endpoint public POST /widget/site/{site}/resource-events).
     */
    public function logClick(
        Site $site,
        string $conversationId,
        ?string $messageId,
        string $resourceType,
        ?string $resourceId,
        ?string $action = null,
        ?string $label = null,
        ?array $metadata = null,
    ): ResourceEvent {
        return $this->log(
            site: $site,
            conversation: $conversationId,
            messageId: $messageId,
            resourceType: $resourceType,
            resourceId: $resourceId,
            eventType: 'click',
            action: $action,
            label: $label,
            metadata: $metadata,
        );
    }

    /**
     * Log une conversion CTA "open_form" -> soumission de formulaire.
     * Retrouve automatiquement le CTA d'origine via chatbot_ctas.value = form_id.
     */
    public function logFormConversion(Site $site, string $formId, string $messageId, string $submissionId): void
    {
        $conversationId = Message::where('id', $messageId)->value('conversation_id');

        if (!$conversationId) {
            return; // message introuvable, on n'écrit rien plutôt qu'un événement orphelin
        }

        $cta = \App\Models\ChatbotCta::where('site_id', $site->id)
            ->where('action', 'open_form')
            ->where('value', $formId)
            ->first();

        $this->log(
            site: $site,
            conversation: $conversationId,
            messageId: $messageId,
            resourceType: 'cta',
            resourceId: $cta?->id,
            eventType: 'conversion',
            action: 'open_form',
            label: $cta?->label,
            metadata: ['submission_id' => $submissionId, 'form_id' => $formId],
        );
    }

    /**
     * Point d'écriture unique — tout passe par ici.
     */
    private function log(
        Site $site,
        Conversation|string $conversation,
        ?string $messageId,
        string $resourceType,
        ?string $resourceId,
        string $eventType,
        ?string $action = null,
        ?string $label = null,
        ?array $metadata = null,
    ): ResourceEvent {
        return ResourceEvent::create([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'conversation_id' => $conversation instanceof Conversation ? $conversation->id : $conversation,
            'message_id' => $messageId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'event_type' => $eventType,
            'action' => $action,
            'label' => $label,
            'metadata' => $metadata,
        ]);
    }
}
