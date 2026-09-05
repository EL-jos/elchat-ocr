<?php

namespace App\Domain\Sales;

use App\Enums\AnalyticsEventType;
use App\Models\Conversation;
use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectingCampaign;
use App\Models\Sales\ProspectingRun;
use App\Models\Site;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Orchestration déterministe : découverte, déduplication et conservation des preuves. */
class ProspectDiscoveryService
{
    public function __construct(
        private readonly ProspectingSourceRegistry $registry,
        private readonly AnalyticsEventService $analytics,
    ) {}

    public function discover(Site $site, Conversation $campaignConversation, ProspectingCampaign $campaign, array $icp, int $limit, ?ProspectingRun $run = null): int
    {
        $created = 0;
        $settings = $campaign->runtimeSettings();
        $selected = $campaign->sources_snapshot ?: ($settings['sources'] ?? ['openstreetmap']);
        $maxSources = max(1, min(count($selected), (int) ($settings['limits']['max_sources_per_run'] ?? count($selected))));

        foreach (array_slice($this->normalizeSelection($selected), 0, $maxSources) as $selection) {
            if ($created >= $limit) {
                break;
            }

            try {
                $source = $this->registry->get($selection['key']);
            } catch (\Throwable $exception) {
                $this->capture($site, AnalyticsEventType::PROSPECTING_SOURCE_FAILED, $campaign, [
                    'source_key' => $selection['key'], 'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $this->capture($site, AnalyticsEventType::PROSPECTING_SOURCE_STARTED, $campaign, ['source_key' => $source->key()]);
            try {
                $options = array_merge($settings['discovery_settings'] ?? [], $selection['settings']);
                $candidates = $source->discover($site, $campaignConversation, $icp, $limit - $created, $options);
            } catch (\Throwable $exception) {
                Log::warning("ProspectDiscoveryService: source '{$source->key()}' failed", ['error' => $exception->getMessage()]);
                $this->capture($site, AnalyticsEventType::PROSPECTING_SOURCE_FAILED, $campaign, [
                    'source_key' => $source->key(), 'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $sourceCreated = 0;
            foreach ($candidates as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                if ($created >= $limit) {
                    break;
                }
                [$prospect, $duplicate] = $this->persistCandidate($site, $campaign, $run, $source->key(), $candidate);
                $this->persistEvidence($prospect, $source->key(), $candidate['evidence'] ?? []);

                if ($duplicate) {
                    $this->capture($site, AnalyticsEventType::PROSPECT_CANDIDATE_DEDUPLICATED, $campaign, [
                        'source_key' => $source->key(), 'prospect_id' => $prospect->id,
                    ]);

                    continue;
                }

                $created++;
                $sourceCreated++;
                $this->capture($site, AnalyticsEventType::PROSPECT_CANDIDATE_DISCOVERED, $campaign, [
                    'source_key' => $source->key(), 'prospect_id' => $prospect->id,
                ]);
            }

            $this->capture($site, AnalyticsEventType::PROSPECTING_SOURCE_COMPLETED, $campaign, [
                'source_key' => $source->key(), 'created' => $sourceCreated, 'candidates' => $candidates->count(),
            ]);
        }

        return $created;
    }

    /** @return array{0: Prospect, 1: bool} */
    private function persistCandidate(Site $site, ProspectingCampaign $campaign, ?ProspectingRun $run, string $sourceKey, array $candidate): array
    {
        $domain = $this->normalizeDomain($candidate['domain'] ?? $candidate['website'] ?? null);
        $email = $this->normalizeEmail($candidate['email'] ?? null);
        $phone = $this->normalizePhone($candidate['phone'] ?? null);
        $displayName = $candidate['company'] ?? $candidate['name'] ?? null;
        $normalizedName = $this->normalizeText($displayName);
        $location = $this->cleanValue($candidate['location'] ?? null);

        $identity = array_filter([$domain, $email, $phone, $normalizedName]);
        $existing = null;
        if ($identity !== []) {
            $existing = Prospect::where('site_id', $site->id)->where(function ($query) use ($domain, $email, $phone, $normalizedName, $location) {
            if ($domain) {
                $query->orWhere('domain', $domain);
            }
            if ($email) {
                $query->orWhere('email', $email);
            }
            if ($phone) {
                $query->orWhere('normalized_phone', $phone);
            }
            if ($normalizedName) {
                $query->orWhere(function ($sub) use ($normalizedName, $location) {
                    $sub->where('normalized_name', $normalizedName);
                    if ($location) {
                        $sub->where('location', $location);
                    }
                });
            }
            })->first();
        }

        if ($existing) {
            $enrichment = array_replace_recursive($existing->enrichment_data ?? [], $candidate['enrichment_data'] ?? []);
            $sources = data_get($enrichment, 'discovery.sources', []);
            $sources = array_values(array_unique(array_merge(is_array($sources) ? $sources : [], [$sourceKey])));
            data_set($enrichment, 'discovery.sources', $sources);
            data_set($enrichment, 'discovery.last_seen_at', now()->toIso8601String());

            $existing->update(array_merge(array_filter([
                'name' => $existing->name ?: ($candidate['name'] ?? null),
                'company' => $existing->company ?: ($candidate['company'] ?? null),
                'website' => $existing->website ?: ($candidate['website'] ?? null),
                'domain' => $existing->domain ?: $domain, 'email' => $existing->email ?: $email,
                'phone' => $existing->phone ?: ($candidate['phone'] ?? null), 'normalized_phone' => $existing->normalized_phone ?: $phone,
                'location' => $existing->location ?: $location, 'address' => $existing->address ?: ($candidate['address'] ?? null),
                'sector' => $existing->sector ?: ($candidate['sector'] ?? null),
                'contact_person' => $existing->contact_person ?: ($candidate['contact_person'] ?? null),
                'other_contact' => $existing->other_contact ?: ($candidate['other_contact'] ?? null),
            ], fn ($value) => $value !== null && $value !== ''), [
                'enrichment_data' => $enrichment,
                'last_activity_at' => now(),
            ]));

            return [$existing->fresh(), true];
        }

        return [Prospect::create([
            'id' => (string) Str::uuid(), 'site_id' => $site->id, 'campaign_id' => $campaign->id,
            'prospecting_run_id' => $run?->id, 'name' => $candidate['name'] ?? null, 'company' => $candidate['company'] ?? null,
            'website' => $candidate['website'] ?? null, 'domain' => $domain, 'email' => $email,
            'phone' => $candidate['phone'] ?? null, 'normalized_phone' => $phone, 'normalized_name' => $normalizedName,
            'source' => $sourceKey, 'location' => $location, 'address' => $candidate['address'] ?? null,
            'sector' => $candidate['sector'] ?? null, 'contact_person' => $candidate['contact_person'] ?? null,
            'other_contact' => $candidate['other_contact'] ?? null, 'enrichment_data' => array_replace_recursive([
                'discovery' => ['sources' => [$sourceKey], 'first_seen_at' => now()->toIso8601String()],
            ], $candidate['enrichment_data'] ?? []),
            'status' => 'discovered', 'crm_ref' => $candidate['crm_ref'] ?? null,
            'crm_sync_status' => ! empty($candidate['crm_ref']) ? 'linked' : 'pending', 'last_activity_at' => now(),
        ]), false];
    }

    private function persistEvidence(Prospect $prospect, string $sourceKey, array $evidence): void
    {
        foreach ($evidence as $item) {
            if (! is_array($item)) {
                continue;
            }
            $prospect->evidence()->create([
                'kind' => $item['type'] ?? 'observation', 'source_key' => $sourceKey, 'source_url' => $item['source_url'] ?? null,
                'field' => $item['field'] ?? null, 'value' => $item['value'] ?? null, 'confidence' => $item['confidence'] ?? null,
                'observed_at' => now(), 'metadata' => $item['metadata'] ?? null,
            ]);
        }
    }

    private function normalizeSelection(array $selected): array
    {
        return collect($selected)->map(fn ($item) => is_string($item)
            ? ['key' => $item, 'settings' => []]
            : ['key' => $item['key'] ?? '', 'settings' => $item['settings'] ?? []])
            ->filter(fn ($item) => $item['key'] !== '')->values()->all();
    }

    private function normalizeDomain(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $value = strtolower(trim($value));
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        return trim((string) preg_replace('/^www\./', '', $value), '.') ?: null;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function normalizePhone(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : preg_replace('/\D+/', '', $value);
    }

    private function normalizeText(?string $value): ?string
    {
        $value = trim(mb_strtolower((string) $value));
        $value = Str::ascii($value);
        $value = trim((string) preg_replace('/\b(ltd|ltee|sarl|sa|inc|co|corp|group|groupe|pvt|plc|company|societe)\b/u', ' ', $value));

        return $value === '' ? null : preg_replace('/\s+/', ' ', $value);
    }

    private function cleanValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function capture(Site $site, AnalyticsEventType $event, ProspectingCampaign $campaign, array $metadata): void
    {
        $this->analytics->capture($site, $event, ['resource_type' => 'sales_prospecting_campaign', 'resource_id' => $campaign->id], $metadata, async: true);
    }
}
