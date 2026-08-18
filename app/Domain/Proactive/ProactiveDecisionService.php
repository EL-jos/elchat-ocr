<?php

namespace App\Domain\Proactive;

use App\Models\Proactive\ProactiveMessage;
use App\Services\hops\LLMService;

class ProactiveDecisionService
{
    public function __construct(
        private readonly ProactiveContextBuilder $contextBuilder,
        private readonly LLMService $llm,
    ) {}

    public function decide(ProactiveMessage $message): array
    {
        $message->loadMissing(['campaign.agent', 'campaign.workflow']);
        $context = $this->contextBuilder->build($message);
        $template = trim((string) (($message->campaign->metadata ?? [])['message_template'] ?? ''));

        if ($message->campaign->decision_mode === 'template' && $template !== '') {
            return [
                'send' => true,
                'message' => mb_substr($template, 0, 1000),
                'reason' => 'approved_campaign_template',
                'evidence' => ['campaign_template'],
                'context' => $context,
            ];
        }

        if ($context['events'] === [] && $context['conversation']['messages'] === [] && $context['knowledge'] === []) {
            return ['send' => false, 'reason' => 'no_contextual_evidence', 'message' => null, 'evidence' => [], 'context' => $context];
        }

        $agent = $message->campaign->agent;
        $workflow = $message->campaign->workflow;
        $response = $this->llm->chatJson([
            ['role' => 'system', 'content' => implode("\n", [
                'Tu décides si un message proactif ELChat est utile et sûr.',
                'N’utilise AUCUN fait absent du contexte JSON. Ne promets aucune action non prouvée.',
                'Si la preuve est insuffisante, si le message serait répétitif, intrusif ou hors sujet, réponds send=false.',
                'Le message doit être autonome, professionnel, naturel, bref, sans mentionner la surveillance ni le moteur interne.',
                'Retourne uniquement: {"send":boolean,"message":string|null,"reason":string,"evidence":[string]}.',
            ])],
            ['role' => 'user', 'content' => json_encode([
                'agent' => ['name' => $agent?->name, 'objective' => $agent?->objective, 'tone' => $agent?->tone, 'tone_instructions' => $agent?->custom_tone_instructions],
                'workflow' => ['name' => $workflow?->name, 'trigger' => $workflow?->trigger_description, 'steps' => $workflow?->steps],
                'campaign_instruction' => $message->campaign->description,
                'approved_template_hint' => $template ?: null,
                'step' => $message->step,
                'context' => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], array_filter([
            'model' => config('proactive.decision_model'),
            'temperature' => 0.15,
            'max_tokens' => 500,
            'response_format' => ['type' => 'json_object'],
        ]));

        $content = isset($response['message']) ? trim((string) $response['message']) : null;
        $send = ($response['send'] ?? false) === true && $content !== '';

        return [
            'send' => $send,
            'message' => $send ? mb_substr($content, 0, 1000) : null,
            'reason' => mb_substr((string) ($response['reason'] ?? 'decision_declined'), 0, 500),
            'evidence' => array_values(array_filter((array) ($response['evidence'] ?? []), 'is_string')),
            'context' => $context,
        ];
    }
}
