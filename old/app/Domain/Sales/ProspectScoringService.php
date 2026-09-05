<?php

namespace App\Domain\Sales;

use App\Models\Sales\Prospect;

/**
 * Scoring déterministe à règles pondérées — jamais un jugement LLM
 * (§10 du cahier des charges : "ne jamais produire un score arbitraire").
 * Chaque règle produit un couple (points, raison lisible), et le score
 * final est la somme brute plafonnée à 100.
 *
 * Les règles n'utilisent QUE les données réellement présentes sur le
 * prospect ou dans l'ICP — jamais une supposition. Une règle dont la
 * donnée nécessaire est absente ne s'applique simplement pas (ni bonus ni
 * malus), plutôt que de deviner.
 */
class ProspectScoringService
{
    /**
     * @param  array  $icp  {sector, company_type, location, company_size, custom_criteria}
     * @param  array  $websiteSignals  résultat de WebsiteIntelligenceService::analyze()
     * @param  array  $criteria  pondérations optionnelles issues de la configuration ICP
     * @return array{score:int, reasons:array<array{points:int,reason:string}>}
     */
    public function score(Prospect $prospect, array $icp, array $websiteSignals = [], array $criteria = []): array
    {
        $prototypeScoring = data_get($prospect->enrichment_data, 'discovery.prototype_scoring');
        if (is_array($prototypeScoring) && ($prototypeScoring['breakdown_provided'] ?? true) !== false) {
            return $this->prototypeScore($prospect, $prototypeScoring);
        }

        $reasons = [];
        $weights = array_merge([
            'sector_match' => 35,
            'location_match' => 20,
            'company_type_match' => 15,
            'need_match' => 20,
            'contactability' => 10,
        ], $criteria['weights'] ?? []);
        $matches = is_array($websiteSignals['icp_matches'] ?? null) ? $websiteSignals['icp_matches'] : [];
        $profile = mb_strtolower(implode(' ', array_filter([
            $prospect->name,
            $prospect->company,
            $prospect->domain,
            $prospect->sector,
            $prospect->crm_ref['company_type'] ?? null,
            $websiteSignals['page_title'] ?? null,
        ])));

        $sectorTerms = $this->terms($icp['sector'] ?? '');
        $sectorMatch = ! empty($matches['sector']) || $this->containsAny($profile, $sectorTerms);
        if ($sectorMatch) {
            $label = $this->matchedLabel($matches['sector'] ?? [], $icp['sector'] ?? '');
            $reasons[] = ['points' => (int) $weights['sector_match'], 'reason' => "Secteur ou rôle correspondant à l'ICP".($label ? " ({$label})" : ''), 'basis' => 'observed'];
        }

        $location = $prospect->location ?: ($matches['location'][0] ?? null);
        if (! empty($icp['location']) && $location !== null && ($this->containsText($location, $icp['location']) || ! empty($matches['location']))) {
            $reasons[] = ['points' => (int) $weights['location_match'], 'reason' => "Localisation observée correspondant à l'ICP ({$location})", 'basis' => 'observed'];
        }

        $companyTypeTerms = $this->terms($icp['company_type'] ?? '');
        $companyType = $prospect->crm_ref['company_type'] ?? null;
        $companyTypeMatch = (! empty($companyType) && $this->containsAny(mb_strtolower((string) $companyType), $companyTypeTerms))
            || ! empty($matches['company_type'])
            || $this->containsAny($profile, $companyTypeTerms);
        if ($companyTypeMatch) {
            $label = $this->matchedLabel($matches['company_type'] ?? [], $icp['company_type'] ?? '');
            $reasons[] = ['points' => (int) $weights['company_type_match'], 'reason' => "Type d'entreprise correspondant à l'ICP".($label ? " ({$label})" : ''), 'basis' => 'observed'];
        }

        $needMatch = ! empty($matches['needs']) || count($matches['custom_criteria'] ?? []) >= 2;
        if ($needMatch) {
            $labels = array_values(array_unique(array_merge($matches['needs'] ?? [], $matches['custom_criteria'] ?? [])));
            $reasons[] = ['points' => (int) $weights['need_match'], 'reason' => 'Besoin ou critère métier de l’ICP observé'.($labels ? ' ('.implode(', ', array_slice($labels, 0, 3)).')' : ''), 'basis' => 'observed'];
        }

        if (! empty($prospect->email) || ! empty($prospect->phone)) {
            $reasons[] = ['points' => (int) $weights['contactability'], 'reason' => 'Un moyen de contact public est disponible', 'basis' => 'observed'];
        } elseif (! empty($prospect->website) && ! empty($websiteSignals['contact_form_only'])) {
            $reasons[] = ['points' => (int) max(1, round($weights['contactability'] * .5)), 'reason' => 'Un formulaire de contact public est disponible', 'basis' => 'observed'];
        }

        $score = min(100, array_sum(array_column($reasons, 'points')));

        return ['score' => $score, 'reasons' => $reasons];
    }

    /**
     * Barème commun au prototype : les sources annuaire apportent un signal
     * d'ICP prudent (+5), puis les champs réellement observés complètent la
     * fiche. Les valeurs provenant du web sont plafonnées avant utilisation.
     *
     * @return array{score:int,reasons:array<int,array<string,mixed>>}
     */
    private function prototypeScore(Prospect $prospect, array $metadata): array
    {
        $points = [
            'Secteur correspondant' => $this->boundedPoints($metadata['sector_points'] ?? (($metadata['sector_matched'] ?? false) ? 25 : 0), 25),
            'Localisation ciblée' => $this->boundedPoints($metadata['location_points'] ?? (($metadata['location_matched'] ?? false) ? 20 : 0), 20),
            'Besoin / pertinence ICP' => $this->boundedPoints($metadata['needs_points'] ?? 5, 15),
            'Site web actif' => ! empty($prospect->website) ? 15 : 0,
            'Coordonnées de contact' => ! empty($prospect->phone) && ! empty($prospect->email)
                ? 15
                : (! empty($prospect->phone) || ! empty($prospect->email) ? 8 : 0),
            'Fiche adresse complète' => ! empty($prospect->address) ? 10 : 0,
        ];

        $reasons = [];
        foreach ($points as $label => $value) {
            $reasons[] = [
                'points' => $value,
                'reason' => $label,
                'basis' => 'observed',
            ];
        }

        return ['score' => min(100, array_sum($points)), 'reasons' => $reasons];
    }

    private function boundedPoints(mixed $value, int $maximum): int
    {
        return max(0, min($maximum, (int) $value));
    }

    /** @return string[] */
    private function terms(string $value): array
    {
        $stopWords = ['avec', 'dans', 'des', 'les', 'une', 'pour', 'site', 'web', 'public', 'moyen', 'possibilite', 'activité', 'activite'];
        $terms = [];
        foreach (preg_split('/[,;|]+/u', mb_strtolower($value)) ?: [] as $part) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $part) ?: [] as $term) {
                $term = trim($term);
                if (mb_strlen($term) >= 5 && ! in_array($term, $stopWords, true)) {
                    $terms[] = $term;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    private function containsText(?string $value, ?string $needle): bool
    {
        return $value !== null && $needle !== null && str_contains(mb_strtolower($value), mb_strtolower($needle));
    }

    /** @param string[] $terms */
    private function containsAny(string $value, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->containsText($value, $term)) {
                return true;
            }
        }

        return false;
    }

    private function matchedLabel(array $matches, string $fallback): string
    {
        return (string) ($matches[0] ?? ($this->terms($fallback)[0] ?? ''));
    }
}
