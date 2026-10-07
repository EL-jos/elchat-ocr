<?php

namespace App\SocialIntelligence\Providers;

use App\Models\Social\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramProvider extends AbstractProvider
{
    public function provider(): string { return 'instagram'; }

    public function capabilities(SocialAccount $account): array
    {
        $permission = $account->access_token ? 'permission_required' : 'not_connected';
        return [
            'content' => $this->status('available', 'Posts, reels et médias du compte professionnel via Instagram Graph API.'),
            'views' => $this->status('permission_required', 'Vues et portée dépendent des permissions Insights et du type de média.', 'instagram_business_manage_insights'),
            'likes' => $this->status($permission === 'not_connected' ? 'not_connected' : 'available', 'Nombre de likes fourni au niveau du média lorsque disponible.'),
            'comments' => $this->status($permission === 'not_connected' ? 'not_connected' : 'available', 'Nombre de commentaires fourni au niveau du média.'),
            'shares' => $this->status('permission_required', 'Partages disponibles seulement pour les métriques Insights autorisées.', 'instagram_business_manage_insights'),
            'audience_growth' => $this->status('permission_required', 'Évolution d’audience via les Insights du compte.', 'instagram_business_manage_insights'),
            'messages' => $this->status('available', 'Messages/commentaires déjà reçus par les webhooks ELChat.'),
        ];
    }

    public function sync(SocialAccount $account, Carbon $from, Carbon $to): array
    {
        $result = $this->unavailablePayload($account, $from, $to);
        $result['limitations'][] = 'Les médias et Insights Instagram nécessitent un compte professionnel/créateur relié et les permissions Meta approuvées.';
        if (!$account->access_token) {
            $result['limitations'][] = 'Jeton Instagram absent.';
            return $result;
        }

        $version = config('services.facebook.graph_version', 'v25.0');
        $base = "https://graph.facebook.com/{$version}";
        try {
            $response = Http::timeout(30)->get("{$base}/{$account->provider_account_id}/media", [
                'fields' => 'id,caption,media_type,media_product_type,timestamp,permalink,like_count,comments_count',
                'limit' => 100,
                'access_token' => $account->access_token,
            ]);
            if ($response->status() === 401 || $response->status() === 403) {
                $result['limitations'][] = 'Instagram a refusé la lecture des médias : permission requise ou jeton expiré.';
                return $result;
            }
            if (!$response->successful()) {
                $result['limitations'][] = 'Instagram est temporairement indisponible pour cette synchronisation.';
                return $result;
            }
            foreach ($response->json('data', []) as $item) {
                $published = isset($item['timestamp']) ? Carbon::parse($item['timestamp']) : null;
                if ($published && ($published->lt($from) || $published->gt($to))) continue;
                $result['contents'][] = [
                    'external_content_id' => (string) ($item['id'] ?? ''),
                    'content_type' => strtolower((string) ($item['media_product_type'] ?? $item['media_type'] ?? 'media')),
                    'title' => null,
                    'body' => $item['caption'] ?? null,
                    'permalink' => $item['permalink'] ?? null,
                    'published_at' => $published,
                    'metadata' => ['media_type' => $item['media_type'] ?? null, 'media_product_type' => $item['media_product_type'] ?? null],
                ];
                $contentId = (string) ($item['id'] ?? '');
                foreach (['like_count' => ['likes', 'Nombre de likes sur le média'], 'comments_count' => ['comments', 'Nombre de commentaires sur le média']] as $field => [$key, $definition]) {
                    if (isset($item[$field]) && is_numeric($item[$field])) {
                        $result['metrics'][] = $this->metric($contentId, $key, $definition, $item[$field], 'instagram_graph_api', false);
                    }
                }
            }
            $insights = Http::timeout(30)->get("{$base}/{$account->provider_account_id}/insights", [
                'metric' => implode(',', config('social-intelligence.instagram_insight_metrics', [])),
                'period' => 'day',
                'since' => $from->timestamp,
                'until' => $to->timestamp,
                'access_token' => $account->access_token,
            ]);
            if ($insights->successful()) {
                $this->appendInsightMetrics($result, $insights->json('data', []), [
                    'views' => ['views', 'Vues des médias Instagram'],
                    'reach' => ['reach', 'Comptes atteints par Instagram'],
                    'follower_count' => ['audience_size', 'Taille de l’audience Instagram'],
                    'accounts_engaged' => ['engaged_users', 'Comptes ayant interagi avec Instagram'],
                    'total_interactions' => ['interactions', 'Interactions totales Instagram'],
                    'likes' => ['likes', 'Likes Instagram'],
                    'comments' => ['comments', 'Commentaires Instagram'],
                    'shares' => ['shares', 'Partages Instagram'],
                    'saved' => ['saves', 'Enregistrements Instagram'],
                ], 'instagram_account_insights');
            } else {
                $result['limitations'][] = 'Instagram Insights indisponible pour ce compte ou cette version Graph API.';
            }
        } catch (\Throwable $exception) {
            Log::warning('Social Intelligence Instagram sync failed', ['account_id' => $account->id, 'error' => $exception->getMessage()]);
            $result['limitations'][] = 'Erreur réseau lors de la synchronisation Instagram.';
        }
        $result['interactions_seen'] = $this->interactions($account, $from, $to);
        return $result;
    }

    private function metric(string $contentId, string $key, string $definition, mixed $value, string $source, bool $comparable): array
    {
        return ['external_content_id' => $contentId, 'metric_key' => $key, 'metric_definition' => $definition, 'value' => (float) $value, 'unit' => 'count', 'source' => $source, 'comparable' => $comparable, 'observed_at' => now()];
    }
}
