<?php

namespace App\Services\analytics;

use App\Enums\AnalyticsAttributionType;
use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;

class ResourceEventLogger
{
    public function __construct(private readonly AnalyticsEventService $analytics)
    {
    }

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
    ): ?AnalyticsEvent {
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
        $message = Message::with('conversation')->find($messageId);
        $conversation = $message?->conversation;

        if (!$conversation || $conversation->site_id !== $site->id) {
            return; // message introuvable, on n'écrit rien plutôt qu'un événement orphelin
        }

        $cta = \App\Models\ChatbotCta::where('site_id', $site->id)
            ->where('action', 'open_form')
            ->where('value', $formId)
            ->first();

        $this->log(
            site: $site,
            conversation: $conversation,
            messageId: $messageId,
            resourceType: 'cta',
            resourceId: $cta?->id,
            eventType: 'conversion',
            action: 'open_form',
            label: $cta?->label,
            metadata: ['submission_id' => $submissionId, 'form_id' => $formId],
        );

        $this->analytics->capture(
            $site,
            AnalyticsEventType::LEAD_CREATED,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'message_id' => $messageId,
                'session_id' => $conversationModel?->metadata['session_id'] ?? null,
                'correlation_id' => $conversationModel?->metadata['session_id'] ?? $conversationId,
                'resource_type' => 'lead',
                'resource_id' => $submissionId,
                'source' => 'chat_form',
                'channel' => $conversation->metadata['channel'] ?? 'widget',
                'attribution_type' => $cta
                    ? AnalyticsAttributionType::DIRECT->value
                    : AnalyticsAttributionType::UNKNOWN->value,
            ],
            metadata: ['form_id' => $formId, 'cta_id' => $cta?->id],
            idempotencyKey: $this->analytics->deterministicKey('lead_created', $submissionId),
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
    ): ?AnalyticsEvent {
        $conversationId = $conversation instanceof Conversation ? $conversation->id : $conversation;
        $conversationModel = $conversation instanceof Conversation ? $conversation : null;
        $canonicalType = $this->analytics->canonicalResourceEventType($resourceType, $eventType);

        return $this->analytics->capture(
            site: $site,
            eventType: $canonicalType,
            context: [
                'visitor_id' => $conversationModel?->visitor_id,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'session_id' => $conversation->metadata['session_id'] ?? null,
                'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'source' => $eventType === 'conversion' ? 'form' : 'chat_response',
                'channel' => $conversationModel?->metadata['channel'] ?? 'widget',
                'action' => $action,
                'label' => $label,
            ],
            metadata: $metadata ?? [],
            idempotencyKey: $this->analytics->resourceIdempotencyKey(
                $site->id, $conversationId, $messageId, $resourceType, $resourceId, $eventType,
            ),
        );
    }
}
