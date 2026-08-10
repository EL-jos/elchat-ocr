<?php

namespace App\Domain\MCP\Capability\Concerns;

use App\Models\Mcp\McpConnector;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Partagé par CapabilityPlaybookEngine (connecteurs) et
 * CapabilityActionPlaybookEngine (combos d'actions) : dans les deux cas,
 * on ne propose RIEN de nouveau tant qu'un connecteur déjà actif échoue en
 * boucle — corriger l'existant passe toujours avant d'en ajouter.
 *
 * ⚠️ 'denied' est exclu des échecs comptés : c'est le système de
 * permissions qui bloque une action comme prévu, pas une panne.
 *
 * ⚠️ La fenêtre d'analyse par connecteur démarre au plus tard de
 * (30 jours en arrière) et (sa dernière reconnexion — mcp_site_connectors.
 * connected_at). Sans ça, un connecteur qui a eu un token expiré puis a
 * été reconnecté traîne pendant un mois entier des échecs qui n'ont plus
 * aucun rapport avec son état actuel — c'est précisément ce qui produisait
 * de faux positifs juste après une reconnexion.
 */
trait DetectsMcpFriction
{
    private const FRICTION_WINDOW_DAYS = 30;
    private const FRICTION_MIN_FAILURES = 5;   // volume minimum pour être significatif
    private const FRICTION_MIN_RATE = 0.25;    // au moins 25% des appels en échec
    private const FRICTION_MAX_ITEMS = 3;

    private function detectFrictions(Site $site): array
    {
        $windowStart = now()->subDays(self::FRICTION_WINDOW_DAYS);

        // Début de fenêtre réel par connecteur : jamais avant sa dernière
        // connexion en date, pour ignorer les échecs d'avant reconnexion.
        $connectedSince = $site->mcpSiteConnectors()
            ->where('status', 'connected')
            ->with('mcpConnector')
            ->get()
            ->filter(fn ($sc) => $sc->mcpConnector !== null)
            ->mapWithKeys(fn ($sc) => [
                $sc->mcpConnector->slug => $sc->connected_at
                    ? max($windowStart, \Illuminate\Support\Carbon::parse($sc->connected_at))
                    : $windowStart,
            ]);

        $logs = DB::table('mcp_audit_logs')
            ->select('connector_slug', 'status', 'created_at')
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $windowStart)
            ->get();

        $offenders = $logs
            ->groupBy('connector_slug')
            ->map(function ($rows, $slug) use ($connectedSince) {
                $since = $connectedSince[$slug] ?? null;
                if (!$since) {
                    return null; // connecteur pas (ou plus) activement connecté : rien à signaler ici
                }

                $relevant = $rows->filter(fn ($r) => \Illuminate\Support\Carbon::parse($r->created_at)->gte($since));
                $total = $relevant->count();
                $failures = $relevant->filter(fn ($r) => in_array($r->status, ['error', 'timeout'], true))->count();

                return (object) [
                    'connector_slug' => $slug, 'total_calls' => $total, 'failures' => $failures,
                    'rate' => $total > 0 ? $failures / $total : 0,
                ];
            })
            ->filter(fn ($row) => $row !== null && $row->failures >= self::FRICTION_MIN_FAILURES && $row->rate >= self::FRICTION_MIN_RATE)
            ->sortByDesc('failures')
            ->take(self::FRICTION_MAX_ITEMS);

        if ($offenders->isEmpty()) {
            return [];
        }

        $meta = McpConnector::whereIn('slug', $offenders->pluck('connector_slug'))->get()->keyBy('slug');

        return $offenders->map(fn ($row) => [
            'type' => 'fix_first',
            'connector' => [
                'slug' => $row->connector_slug,
                'name' => $meta[$row->connector_slug]->name ?? $row->connector_slug,
                'icon_url' => $meta[$row->connector_slug]->icon_url ?? null,
            ],
            'failures_last_30_days' => $row->failures,
            'total_calls_last_30_days' => $row->total_calls,
            'failure_rate' => round($row->rate, 2),
            'message' => "Ce connecteur échoue sur " . round($row->rate * 100) . "% de ses appels depuis sa dernière connexion ({$row->failures}/{$row->total_calls}) — à vérifier avant d'en activer d'autres.",
        ])->values()->all();
    }
}
