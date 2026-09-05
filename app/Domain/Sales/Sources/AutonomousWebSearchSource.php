<?php

namespace App\Domain\Sales\Sources;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\hops\LLMService;
use Illuminate\Support\Collection;

/** Recherche web autonome via le server tool web_search d’OpenRouter. */
class AutonomousWebSearchSource implements ProspectSourceInterface
{
    public function __construct(private readonly LLMService $llm) {}

    public function key(): string
    {
        return 'web_search';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        if (! config('prospecting.web_search.enabled', true) || ! config('mcp.llm.api_key')) {
            throw new \RuntimeException('La recherche web autonome n’est pas configurée côté ELChat.');
        }

        $limit = max(1, min(50, $limit));
        $maxResults = max(1, min(10, (int) config('prospecting.web_search.max_results', 5)));
        $maxTotalResults = max($maxResults, min(30, (int) config('prospecting.web_search.max_total_results', 15)));
        $prompt = $this->prompt($icp, $limit);

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => 'Tu es un moteur de prospection B2B fiable, factuel et strict sur les preuves publiques.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'task' => 'prospecting_web_search',
            'max_tokens' => min(5000, max(1800, $limit * 420)),
            'max_tokens_cap' => 8000,
            'detect_truncation' => true,
            'response_format' => ['type' => 'json_object'],
            'tools' => [[
                'type' => 'openrouter:web_search',
                'parameters' => [
                    'engine' => config('prospecting.web_search.engine', 'auto'),
                    'max_results' => $maxResults,
                    'max_total_results' => $maxTotalResults,
                ],
            ]],
        ]);

        $prospects = $result['prospects'] ?? [];
        if (! is_array($prospects)) {
            throw new \RuntimeException('La recherche web autonome a renvoyé un format inexploitable.');
        }

        return collect($prospects)
            ->map(fn ($candidate) => $this->normalizeCandidate($candidate, $icp))
            ->filter()
            ->unique(fn (array $candidate) => $candidate['domain'] ?? $candidate['external_key'] ?? mb_strtolower($candidate['name']))
            ->take($limit)
            ->values();
    }

    private function prompt(array $icp, int $limit): string
    {
        $profile = json_encode([
            'needs' => $icp['needs'] ?? '',
            'sector' => $icp['sector'] ?? '',
            'location' => $icp['location'] ?? '',
            'company_size' => $icp['company_size'] ?? '',
            'company_type' => $icp['company_type'] ?? '',
            'custom_criteria' => $icp['custom_criteria'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Effectue une vraie recherche web autonome avec ton outil de recherche web.
Trouve au maximum {$limit} entreprises réelles qui correspondent à l’ICP ci-dessous.
Recherche plusieurs variantes de secteurs et de localisation si nécessaire, puis vérifie chaque résultat sur une source publique fiable.
Privilégie le site officiel de l’entreprise et les annuaires professionnels sérieux.
Ne considère jamais le site de l’annuaire lui-même comme un prospect lorsque la page présente des entreprises tierces.
Ne crée et ne déduis aucune coordonnée : email, téléphone, adresse et URL doivent être explicitement observés dans les sources.
Si une donnée n’est pas vérifiable, renvoie une chaîne vide.

ICP JSON :
{$profile}

Réponds exclusivement avec un JSON valide de cette forme :
{"prospects":[{"name":"","company":"","address":"","website":"","email":"","phone":"","location":"","sector":"","contact_person":"","other_contact":"","score_breakdown":[{"label":"Secteur correspondant","points":0},{"label":"Localisation ciblée","points":0},{"label":"Besoin / pertinence ICP","points":0},{"label":"Site web actif","points":0},{"label":"Coordonnées de contact","points":0},{"label":"Fiche adresse complète","points":0}],"reason":"","evidence":[{"url":"","title":"","claim":""}]}]}
Chaque prospect doit avoir au moins une preuve avec une URL http(s) réellement consultée.
La raison doit distinguer les faits observés des inférences prudentes et rester courte.
Pour le score_breakdown, utilise exactement les six labels fournis, sans dépasser respectivement 25, 20, 15, 15, 15 et 10 points. Ne donne des points que pour des faits vérifiés.
Ne mentionne jamais ELChat comme critère de qualification et ne parle pas de chatbot ou de formulaire de contact sauf si cela fait partie explicitement de l’ICP.
PROMPT;
    }

    private function normalizeCandidate(mixed $raw, array $icp): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $name = $this->stringValue($raw['company'] ?? $raw['name'] ?? null);
        if (! $name) {
            return null;
        }

        $website = $this->normalizeUrl($raw['website'] ?? null);
        $evidence = [];
        foreach (is_array($raw['evidence'] ?? null) ? $raw['evidence'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $this->normalizeUrl($item['url'] ?? null);
            if (! $url) {
                continue;
            }
            $evidence[] = [
                'type' => 'observation',
                'field' => 'public_web_data',
                'value' => [
                    'title' => $this->stringValue($item['title'] ?? null),
                    'claim' => $this->stringValue($item['claim'] ?? null),
                ],
                'source_url' => $url,
                'confidence' => 0.85,
            ];
        }
        if (! $evidence && $website) {
            $evidence[] = [
                'type' => 'observation', 'field' => 'website', 'value' => ['url' => $website],
                'source_url' => $website, 'confidence' => 0.75,
            ];
        }
        if (! $evidence) {
            return null;
        }

        $email = filter_var($this->stringValue($raw['email'] ?? null), FILTER_VALIDATE_EMAIL) ?: null;
        $phone = $this->stringValue($raw['phone'] ?? null);
        $address = $this->stringValue($raw['address'] ?? null);
        $contactPerson = $this->stringValue($raw['contact_person'] ?? null);
        $otherContact = $this->stringValue($raw['other_contact'] ?? null);
        $sourceUrl = $evidence[0]['source_url'];
        $prototypeScoring = $this->normalizePrototypeScoring($raw['score_breakdown'] ?? []);

        return [
            'name' => $name,
            'company' => $name,
            'website' => $website,
            'domain' => $website ? parse_url($website, PHP_URL_HOST) : null,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'contact_person' => $contactPerson,
            'other_contact' => $otherContact,
            'location' => $this->stringValue($raw['location'] ?? null) ?: ($address ?: ($icp['location'] ?? null)),
            'sector' => $this->stringValue($raw['sector'] ?? null),
            'external_key' => 'web-search:'.hash('sha256', ($website ?: $sourceUrl).'|'.mb_strtolower($name)),
            'source_url' => $sourceUrl,
            'enrichment_data' => [
                'discovery' => [
                    'prototype_scoring' => $prototypeScoring,
                ],
            ],
            'evidence' => $evidence,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePrototypeScoring(mixed $raw): array
    {
        $limits = [25, 20, 15, 15, 15, 10];
        $keys = ['sector_points', 'location_points', 'needs_points', 'website_points', 'contact_points', 'address_points'];
        $breakdown = is_array($raw) ? array_values($raw) : [];
        $hasBreakdown = count($breakdown) >= count($keys);
        $points = [];

        foreach ($keys as $index => $key) {
            $value = $breakdown[$index]['points'] ?? 0;
            $points[$key] = max(0, min($limits[$index], (int) $value));
        }

        return [
            'sector_points' => $points['sector_points'],
            'location_points' => $points['location_points'],
            'needs_points' => $points['needs_points'],
            'website_points' => $points['website_points'],
            'contact_points' => $points['contact_points'],
            'address_points' => $points['address_points'],
            'breakdown_provided' => $hasBreakdown,
        ];
    }

    private function normalizeUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
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
