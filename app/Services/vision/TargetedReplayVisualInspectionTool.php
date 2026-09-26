<?php

namespace App\Services\vision;

use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared named tool for inspecting bounded visual evidence extracted from
 * targeted rrweb replay moments.
 *
 * Consumers must provide screenshots selected by the replay investigation.
 * Raw rrweb chunks are intentionally not accepted or forwarded to the LLM.
 *
 * The vision model is deliberately limited to visible facts. Behavioural
 * signals are attached from the associated event context and interpreted by
 * the reasoning model, never invented by the screenshot model.
 */
final class TargetedReplayVisualInspectionTool
{
    public const NAME = 'targeted_replay_visual_inspection';

    public function __construct(private readonly LLMService $llm)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<int, array<string, mixed>> $visualEvidence
     * @return array{tool: string, status: string, model: string|null, observations: array<int, array<string, mixed>>, error: string|null}
     */
    public function inspect(array $visualEvidence): array
    {
        $visualEvidence = $this->prepareEvidence($visualEvidence);
        if ($visualEvidence === []) {
            return $this->result('not_requested');
        }

        $visionModel = trim((string) config(
            'llm.tools.targeted_replay_visual_inspection.model',
            'qwen/qwen3.6-plus',
        ));
        if ($visionModel === '') {
            return $this->result('unavailable', error: 'vision_model_missing');
        }

        $content = [[
            'type' => 'text',
            'text' => json_encode([
                'tool' => self::NAME,
                'task' => 'Inspecter les captures des moments ciblés d’un parcours web.',
                'instruction' => 'Décris uniquement ce qui est réellement visible dans chaque image. Ne déduis pas qu’un élément a été lu, cliqué, ignoré, regardé ou abandonné. Ne produis aucun signal de friction, d’attention, d’intention ou de causalité. Ne décris pas les visiteurs en général. Ne fabrique aucun sélecteur DOM, texte, lien, position ou état qui ne soit pas visible. Si une image est vide, illisible ou ambiguë, indique-le explicitement.',
                'output_schema' => [
                    'observations' => [[
                        'visual_evidence_id' => 'string obligatoire, repris exactement depuis les métadonnées',
                        'scene_summary' => 'string',
                        'visual_facts' => ['string factuelle, uniquement vérifiable dans l’image'],
                        'visible_elements' => [[
                            'kind' => 'string|null',
                            'label_or_text' => 'string|null',
                            'location' => 'string|null',
                            'state' => 'string|null',
                        ]],
                        'visible_text' => 'string|null',
                        'limitations' => ['string'],
                        'confidence' => 'number 0-100',
                    ]],
                ],
                'contract' => 'Chaque observation doit être rattachée à un visual_evidence_id fourni. Une observation visuelle est une preuve d’état affiché à un instant, pas une preuve de comportement, d’attention ou d’intention. Les événements associés sont fournis séparément au modèle de raisonnement.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]];

        foreach ($visualEvidence as $visual) {
            $content[] = [
                'type' => 'text',
                'text' => json_encode([
                    'visual_evidence_id' => $visual['visual_evidence_id'],
                    'session_id' => $visual['session_id'],
                    'moment_id' => $visual['moment_id'],
                    'reason' => $visual['reason'],
                    'replay_timestamp' => $visual['replay_timestamp'],
                    'page' => $visual['page'],
                    'scroll' => $visual['scroll'],
                    'event_ids' => $visual['event_ids'],
                    'event_types' => $visual['event_types'],
                    'pointer_moves_since_previous' => $visual['pointer_moves_since_previous'],
                    'event_context' => $visual['event_context'],
                    'replay_extractor_hints' => [
                        'visible_text' => $visual['visible_text'],
                        'visible_elements' => $visual['visible_elements'],
                    ],
                    'instruction' => 'Cette image correspond exactement à cet identifiant. Analyse l’image seulement pour les faits visuels. Ne conclus rien sur le comportement à partir de ces métadonnées.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $visual['capture']],
            ];
        }

        try {
            $raw = $this->llm->chatJson([
                [
                    'role' => 'system',
                    'content' => 'Tu es le modèle de vision d’ELChat. Tu inspectes des captures rrweb ciblées pour fournir des observations visuelles factuelles à un autre modèle. Réponds uniquement avec un objet JSON valide. N’invente jamais un élément non visible et ne produis jamais d’inférence comportementale.',
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ], [
                'task' => 'vision',
                'model' => $visionModel,
                // This tool has no text-only fallback: a fallback model must
                // never claim to have inspected screenshots it did not see.
                'fallback_models' => [],
                'temperature' => 0.1,
                'max_tokens' => 3200,
                'max_tokens_cap' => 5200,
                'detect_truncation' => true,
                'response_format' => ['type' => 'json_object'],
                'request_timeout' => (int) config('llm.tools.targeted_replay_visual_inspection.request_timeout', 45),
            ]);

            $observations = $this->normalizeVisualObservations($raw, $visualEvidence);
            if ($observations === []) {
                throw new \RuntimeException('targeted_replay_visual_inspection_empty_json');
            }

            return [
                'tool' => self::NAME,
                'status' => 'ready',
                'model' => $this->llm->lastUsedModel() ?: $visionModel,
                'observations' => $observations,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Targeted replay visual inspection unavailable', [
                'tool' => self::NAME,
                'model' => $visionModel,
                'capture_count' => count($visualEvidence),
                'error' => Str::limit($exception->getMessage(), 500, ''),
            ]);

            return $this->result('unavailable', error: Str::limit($exception->getMessage(), 500, ''));
        }
    }

    /** @param array<int, array<string, mixed>> $evidence */
    private function prepareEvidence(array $evidence): array
    {
        return collect($evidence)->map(function (mixed $item): ?array {
            if (! is_array($item) || empty($item['capture'])) return null;

            $evidenceId = (string) ($item['visual_evidence_id'] ?? $item['evidence_id'] ?? '');
            if ($evidenceId === '') return null;

            return [
                'visual_evidence_id' => $evidenceId,
                'evidence_id' => $evidenceId,
                'session_id' => (string) ($item['session_id'] ?? ''),
                'moment_id' => (string) ($item['moment_id'] ?? ''),
                'reason' => $item['reason'] ?? null,
                'replay_timestamp' => $item['replay_timestamp'] ?? null,
                'page' => $item['page'] ?? null,
                'scroll' => $item['scroll'] ?? null,
                'event_ids' => array_values((array) ($item['event_ids'] ?? [])),
                'event_types' => array_values((array) ($item['event_types'] ?? [])),
                'pointer_moves_since_previous' => is_numeric($item['pointer_moves_since_previous'] ?? null)
                    ? max(0, (int) $item['pointer_moves_since_previous'])
                    : 0,
                'event_context' => is_array($item['event_context'] ?? null)
                    ? array_slice($item['event_context'], 0, 12)
                    : [],
                'visible_text' => $this->safeText($item['visible_text'] ?? null, 1200),
                'visible_elements' => array_slice((array) ($item['visible_elements'] ?? []), 0, 25),
                'capture' => (string) $item['capture'],
            ];
        })->filter()->values()->take(12)->all();
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, array<string, mixed>> $visualEvidence
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVisualObservations(array $raw, array $visualEvidence): array
    {
        $validEvidence = collect($visualEvidence)->keyBy('visual_evidence_id');
        $items = $raw['observations'] ?? $raw['visual_observations'] ?? [];
        if (! is_array($items)) return [];

        return collect($items)->map(function (mixed $item) use ($validEvidence): ?array {
            if (! is_array($item)) return null;
            $evidenceId = (string) ($item['visual_evidence_id'] ?? $item['evidence_id'] ?? '');
            $source = $validEvidence->get($evidenceId);
            if (! $source) return null;

            return [
                'visual_evidence_id' => $evidenceId,
                'evidence_id' => $evidenceId,
                'session_id' => $source['session_id'],
                'moment_id' => $source['moment_id'],
                'replay_timestamp' => $source['replay_timestamp'],
                'page' => $source['page'],
                'reason' => $source['reason'],
                'event_ids' => $source['event_ids'],
                'event_types' => $source['event_types'],
                'pointer_moves_since_previous' => $source['pointer_moves_since_previous'],
                'event_context' => $source['event_context'],
                'scene_summary' => $this->safeText($item['scene_summary'] ?? $item['summary'] ?? null, 1200),
                'visual_facts' => $this->boundedStringList($item['visual_facts'] ?? [], 12, 400),
                'visible_elements' => $this->normalizeVisualElements($item['visible_elements'] ?? []),
                'visible_text' => $this->safeText($item['visible_text'] ?? null, 1600),
                'limitations' => $this->boundedStringList($item['limitations'] ?? [], 10, 400),
                'confidence' => is_numeric($item['confidence'] ?? null)
                    ? max(0, min(100, (int) $item['confidence']))
                    : null,
            ];
        })->filter()->values()->take(12)->all();
    }

    /** @return array<int, array<string, string|null>> */
    private function normalizeVisualElements(mixed $value): array
    {
        if (! is_array($value)) return [];

        return collect($value)->map(function (mixed $element): ?array {
            if (is_string($element)) {
                $text = $this->safeText($element, 300);
                return $text ? [
                    'kind' => null,
                    'label_or_text' => $text,
                    'location' => null,
                    'state' => null,
                ] : null;
            }
            if (! is_array($element)) return null;

            return [
                'kind' => $this->safeText($element['kind'] ?? $element['type'] ?? null, 100),
                'label_or_text' => $this->safeText($element['label_or_text'] ?? $element['label'] ?? $element['text'] ?? null, 300),
                'location' => $this->safeText($element['location'] ?? $element['position'] ?? null, 160),
                'state' => $this->safeText($element['state'] ?? null, 160),
            ];
        })->filter()->values()->take(20)->all();
    }

    /** @return array<int, string> */
    private function boundedStringList(mixed $value, int $limit, int $itemLimit): array
    {
        $items = is_array($value) ? $value : (is_scalar($value) ? [$value] : []);
        return collect($items)
            ->map(fn (mixed $item): ?string => $this->safeText($item, $itemLimit))
            ->filter()
            ->values()
            ->take($limit)
            ->all();
    }

    /** @return array{tool: string, status: string, model: string|null, observations: array<int, array<string, mixed>>, error: string|null} */
    private function result(string $status, ?string $error = null): array
    {
        return [
            'tool' => self::NAME,
            'status' => $status,
            'model' => null,
            'observations' => [],
            'error' => $error,
        ];
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if ($value === null || ! is_scalar($value)) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? $text;
        $text = preg_replace('/(?:\+?\d[\d .()\-]{7,}\d)/u', '[phone]', $text) ?? $text;
        return Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, $limit, '…');
    }
}
