<?php

namespace App\Domain\Sales;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Models\Conversation;
use App\Models\Sales\ProspectingCampaign;
use App\Models\Sales\Prospect;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestration DÉTERMINISTE (aucun LLM ici) : interroge les sources
 * disponibles, déduplique, persiste. La qualification/le scoring/la
 * rédaction — qui bénéficient d'un jugement — sont délégués ensuite,
 * prospect par prospect, à MCPActionGateService::runForAgent() (voir job).
 */
class ProspectDiscoveryService
{
    /** @param ProspectSourceInterface[] $sources */
    public function __construct(private readonly array $sources)
    {
    }

    public function discover(Site $site, Conversation $campaignConversation, ProspectingCampaign $campaign, array $icp, int $limit): int
    {
        $created = 0;

        foreach ($this->sources as $source) {
            if ($created >= $limit) break;

            try {
                $candidates = $source->discover($site, $campaignConversation, $icp, $limit - $created);
            } catch (\Throwable $e) {
                Log::warning("ProspectDiscoveryService: source '{$source->key()}' a échoué", ['error' => $e->getMessage()]);
                continue; // une source en échec ne bloque jamais les autres
            }

            foreach ($candidates as $candidate) {
                if ($created >= $limit) break;
                if ($this->isDuplicate($site, $candidate)) continue;

                Prospect::create([
                    'id' => (string) Str::uuid(), 'site_id' => $site->id, 'campaign_id' => $campaign->id,
                    'name' => $candidate['name'] ?? null, 'company' => $candidate['company'] ?? null,
                    'website' => $candidate['website'] ?? null, 'domain' => $candidate['domain'] ?? null,
                    'email' => $candidate['email'] ?? null, 'phone' => $candidate['phone'] ?? null,
                    'source' => $source->key(), 'location' => $candidate['location'] ?? null,
                    'sector' => $candidate['sector'] ?? null, 'status' => 'discovered',
                    'crm_ref' => $candidate['crm_ref'] ?? null, 'last_activity_at' => now(),
                ]);

                $created++;
            }
        }

        return $created;
    }

    /** §12 du cahier des charges : domain/email en priorité, jamais deux fois le même prospect. */
    private function isDuplicate(Site $site, array $candidate): bool
    {
        $domain = $candidate['domain'] ?? null;
        $email = $candidate['email'] ?? null;

        if (!$domain && !$email) {
            return false; // rien à dédupliquer dessus — accepté tel quel (cas rare, CRM sans site ni email)
        }

        return Prospect::where('site_id', $site->id)
            ->where(function ($q) use ($domain, $email) {
                if ($domain) $q->orWhere('domain', $domain);
                if ($email) $q->orWhere('email', $email);
            })->exists();
    }
}
