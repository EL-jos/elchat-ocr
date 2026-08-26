<?php

namespace App\Domain\Sales;

use App\Models\Sales\Prospect;
use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Complète une fiche prospect à partir de preuves publiques vérifiées. */
class ProspectInformationCompletionService
{
    public function __construct(private readonly LLMService $llm) {}

    /**
     * @return array{fields: array<string, string>, evidence: array<int, array<string, mixed>>}
     */
    public function complete(Prospect $prospect, array $icp): array
    {
        $empty = ['fields' => [], 'evidence' => []];
        if (! config('prospecting.web_search.completion_enabled', true) || ! config('mcp.llm.api_key')) {
            return $empty;
        }

        $missing = array_values(array_filter([
            'website', 'email', 'phone', 'address', 'contact_person', 'other_contact',
        ], fn (string $field) => blank($prospect->{$field})));
        if ($missing === []) {
            return $empty;
        }

        try {
            $result = $this->llm->chatJson([
                ['role' => 'system', 'content' => 'Tu complètes des fiches de prospects B2B avec des données publiques, vérifiables et strictement sourcées.'],
                ['role' => 'user', 'content' => $this->prompt($prospect, $icp, $missing)],
            ], [
                'model' => config('prospecting.web_search.model'),
                'max_tokens' => 1800,
                'max_tokens_cap' => 3500,
                'detect_truncation' => true,
                'response_format' => ['type' => 'json_object'],
                'tools' => [[
                    'type' => 'openrouter:web_search',
                    'parameters' => [
                        'engine' => config('prospecting.web_search.engine', 'auto'),
                        'max_results' => max(1, min(10, (int) config('prospecting.web_search.completion_max_results', 3))),
                        'max_total_results' => max(1, min(20, (int) config('prospecting.web_search.completion_max_total_results', 10))),
                    ],
                ]],
            ]);
        } catch (Throwable $exception) {
            // La complétion est une amélioration non bloquante : un fournisseur
            // web indisponible ne doit pas rejeter un prospect déjà découvert.
            Log::warning('ProspectInformationCompletionService: recherche impossible', [
                'prospect_id' => $prospect->id,
                'error' => $exception->getMessage(),
            ]);

            return $empty;
        }

        $candidate = $result['prospect'] ?? ($result['prospects'][0] ?? null);
        if (! is_array($candidate)) {
            return $empty;
        }

        $evidence = $this->normalizeEvidence($candidate['evidence'] ?? [], $candidate['website'] ?? null);
        if ($evidence === []) {
            return $empty;
        }

        $fields = [];
        foreach (['website', 'email', 'phone', 'address', 'contact_person', 'other_contact'] as $field) {
            if (! blank($prospect->{$field})) {
                continue;
            }

            $value = $this->fieldValue($field, $candidate[$field] ?? null);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        if (blank($prospect->location)) {
            $location = $this->fieldValue('location', $candidate['location'] ?? null);
            if ($location !== null) {
                $fields['location'] = $location;
            }
        }

        if (blank($prospect->sector)) {
            $sector = $this->fieldValue('sector', $candidate['sector'] ?? null);
            if ($sector !== null) {
                $fields['sector'] = $sector;
            }
        }

        if (isset($fields['website'])) {
            $domain = parse_url($fields['website'], PHP_URL_HOST);
            if (is_string($domain) && $domain !== '') {
                $fields['domain'] = preg_replace('/^www\./i', '', strtolower($domain));
            }
        }
        if (isset($fields['phone'])) {
            $normalizedPhone = preg_replace('/\D+/', '', $fields['phone']);
            if ($normalizedPhone !== '') {
                $fields['normalized_phone'] = $normalizedPhone;
            }
        }

        return $fields === [] ? $empty : ['fields' => $fields, 'evidence' => $evidence];
    }

    private function prompt(Prospect $prospect, array $icp, array $missing): string
    {
        $profile = json_encode([
            'needs' => $icp['needs'] ?? '',
            'sector' => $icp['sector'] ?? '',
            'location' => $icp['location'] ?? '',
            'company_size' => $icp['company_size'] ?? '',
            'company_type' => $icp['company_type'] ?? '',
            'custom_criteria' => $icp['custom_criteria'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $identity = json_encode([
            'name' => $prospect->name,
            'company' => $prospect->company,
            'website' => $prospect->website,
            'email' => $prospect->email,
            'phone' => $prospect->phone,
            'location' => $prospect->location,
            'sector' => $prospect->sector,
            'address' => $prospect->address,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $missingFields = implode(', ', $missing);

        return <<<PROMPT
Complète la fiche de cette entreprise précise avec une vraie recherche web autonome.
Utilise plusieurs résultats publics si nécessaire et vérifie que les informations appartiennent bien à la même entreprise.
Les champs déjà remplis sont des observations existantes : ne les remplace jamais.
Les champs à compléter sont : {$missingFields}.

ICP du tenant :
{$profile}

Identité déjà connue :
{$identity}

Règles strictes :
- Ne déduis et n'invente jamais une adresse, un téléphone, un email, un site ou un nom de contact.
- Une information ne peut être renvoyée que si elle est explicitement visible dans une source publique consultée.
- Privilégie le site officiel, sa page contact, ses mentions légales et les annuaires professionnels fiables.
- `other_contact` peut contenir un réseau social, WhatsApp ou un formulaire public réellement observé.
- Retourne une chaîne vide pour toute information non trouvée.
- Ne parle pas d'ELChat, de chatbot ou de formulaire comme critère commercial : il s'agit uniquement de compléter la fiche.
- Réponds uniquement avec ce JSON valide :
{"prospect":{"website":"","email":"","phone":"","address":"","contact_person":"","other_contact":"","location":"","sector":"","evidence":[{"url":"","title":"","claim":""}]}}
- Chaque information retournée doit être couverte par au moins une preuve URL http(s).
PROMPT;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeEvidence(mixed $rawEvidence, mixed $fallbackWebsite): array
    {
        $evidence = [];
        foreach (is_array($rawEvidence) ? $rawEvidence : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $this->urlValue($item['url'] ?? null);
            if (! $url) {
                continue;
            }
            $evidence[] = [
                'type' => 'observation',
                'field' => 'public_profile_completion',
                'value' => [
                    'title' => $this->stringValue($item['title'] ?? null),
                    'claim' => $this->stringValue($item['claim'] ?? null),
                ],
                'source_url' => $url,
                'confidence' => 0.85,
            ];
        }

        if ($evidence === []) {
            $url = $this->urlValue($fallbackWebsite);
            if ($url) {
                $evidence[] = [
                    'type' => 'observation',
                    'field' => 'public_profile_completion',
                    'value' => ['claim' => 'Site public vérifié lors de la complétion.'],
                    'source_url' => $url,
                    'confidence' => 0.75,
                ];
            }
        }

        return $evidence;
    }

    private function fieldValue(string $field, mixed $value): ?string
    {
        $value = $this->stringValue($value);
        if ($value === null) {
            return null;
        }

        if ($field === 'website') {
            return $this->urlValue($value);
        }
        if ($field === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_strtolower($value) : null;
        }

        return $value;
    }

    private function urlValue(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('#^https?://#i', trim($value))) {
            return null;
        }

        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
