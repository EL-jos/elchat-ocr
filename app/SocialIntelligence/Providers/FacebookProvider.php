<?php

namespace App\SocialIntelligence\Providers;

use App\Models\Social\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookProvider extends AbstractProvider
{
    public function provider(): string { return 'facebook'; }

    public function capabilities(SocialAccount $account): array
    {
        $connected = $account->access_token ? 'available' : 'not_connected';
        return [
            'content' => $this->status($connected, 'Publications de la Page accessibles via Page Graph API.'),
            'views' => $this->status('permission_required', 'Portée et vues dépendent de Page Insights et des métriques disponibles dans la version Graph.', 'read_insights'),
            'likes' => $this->status($connected, 'Réactions agrégées sur les publications lorsque le champ est renvoyé.'),
            'comments' => $this->status($connected, 'Commentaires agrégés sur les publications.'),
            'shares' => $this->status($connected, 'Partages agrégés sur les publications lorsque le champ est renvoyé.'),
            'audience_growth' => $this->status('permission_required', 'Croissance des abonnés via Page Insights.', 'read_insights'),
            'messages' => $this->status($connected, 'Messages/commentaires déjà reçus par les webhooks ELChat.'),
        ];
    }

    public function sync(SocialAccount $account, Carbon $from, Carbon $to): array
    {
        $result = $this->unavailablePayload($account, $from, $to);
        if (!$account->access_token) {
            $result['limitations'][] = 'Jeton Page Facebook absent.';
            return $result;
        }
        $version = config('services.facebook.graph_version', 'v25.0');
        try {
            $response = Http::timeout(30)->get("https://graph.facebook.com/{$version}/{$account->provider_account_id}/published_posts", [
                'fields' => 'id,message,created_time,permalink_url,shares,comments.limit(0).summary(true),reactions.limit(0).summary(true)',
                'since' => $from->timestamp,
                'until' => $to->timestamp,
                'limit' => 100,
                'access_token' => $account->access_token,
            ]);
            if ($response->status() === 401 || $response->status() === 403) {
                $result['limitations'][] = 'Facebook a refusé la lecture des publications : permission requise ou jeton expiré.';
                return $result;
            }
            if (!$response->successful()) {
                $result['limitations'][] = 'Facebook est temporairement indisponible pour cette synchronisation.';
                return $result;
            }
            foreach ($response->json('data', []) as $item) {
                $published = isset($item['created_time']) ? Carbon::parse($item['created_time']) : null;
                $result['contents'][] = [
                    'external_content_id' => (string) ($item['id'] ?? ''), 'content_type' => 'post',
                    'title' => null, 'body' => $item['message'] ?? null, 'permalink' => $item['permalink_url'] ?? null,
                    'published_at' => $published, 'metadata' => [],
                ];
                $contentId = (string) ($item['id'] ?? '');
                $metrics = [
                    'reactions' => [$item['reactions']['summary']['total_count'] ?? null, 'Nombre de réactions sur la publication'],
                    'comments' => [$item['comments']['summary']['total_count'] ?? null, 'Nombre de commentaires sur la publication'],
                    'shares' => [$item['shares']['count'] ?? null, 'Nombre de partages de la publication'],
                ];
                foreach ($metrics as $key => [$value, $definition]) {
                    if (is_numeric($value)) $result['metrics'][] = $this->metric($contentId, $key, $definition, $value);
                }
            }
            $insights = Http::timeout(30)->get("https://graph.facebook.com/{$version}/{$account->provider_account_id}/insights", [
                'metric' => implode(',', config('social-intelligence.facebook_insight_metrics', [])),
                'period' => 'day',
                'since' => $from->timestamp,
                'until' => $to->timestamp,
                'access_token' => $account->access_token,
            ]);
            if ($insights->successful()) {
                $this->appendInsightMetrics($result, $insights->json('data', []), [
                    'page_impressions' => ['impressions', 'Nombre d’impressions de la Page'],
                    'page_engaged_users' => ['engaged_users', 'Utilisateurs ayant interagi avec la Page'],
                    'page_fans' => ['audience_size', 'Nombre de fans de la Page'],
                    'page_fan_adds' => ['audience_gained', 'Nouveaux fans de la Page'],
                    'page_fan_removes' => ['audience_lost', 'Fans perdus par la Page'],
                ], 'facebook_page_insights');
            } else {
                $result['limitations'][] = 'Facebook Page Insights indisponible pour ce compte ou cette version Graph API.';
            }
        } catch (\Throwable $exception) {
            Log::warning('Social Intelligence Facebook sync failed', ['account_id' => $account->id, 'error' => $exception->getMessage()]);
            $result['limitations'][] = 'Erreur réseau lors de la synchronisation Facebook.';
        }
        $result['interactions_seen'] = $this->interactions($account, $from, $to);
        return $result;
    }

    private function metric(string $contentId, string $key, string $definition, mixed $value): array
    {
        return ['external_content_id' => $contentId, 'metric_key' => $key, 'metric_definition' => $definition, 'value' => (float) $value, 'unit' => 'count', 'source' => 'facebook_graph_api', 'comparable' => false, 'observed_at' => now()];
    }
}
