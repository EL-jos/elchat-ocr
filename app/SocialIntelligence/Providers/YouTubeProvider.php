<?php

namespace App\SocialIntelligence\Providers;

use App\Models\Social\SocialAccount;
use App\SocialChannels\YouTube\YouTubeChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class YouTubeProvider extends AbstractProvider
{
    public function __construct(private readonly YouTubeChannel $channel) {}
    public function provider(): string { return 'youtube'; }

    public function capabilities(SocialAccount $account): array
    {
        $connected = $account->access_token ? 'available' : 'not_connected';
        return [
            'content' => $this->status($connected, 'Vidéos de la playlist uploads via YouTube Data API.'),
            'views' => $this->status($connected, 'Vues vidéo depuis la ressource statistics.'),
            'likes' => $this->status($connected, 'Likes vidéo depuis la ressource statistics.'),
            'comments' => $this->status($connected, 'Commentaires vidéo depuis la ressource statistics et les commentaires synchronisés.'),
            'shares' => $this->status('permission_required', 'Partages journaliers via YouTube Analytics API avec scope analytics.readonly.', 'https://www.googleapis.com/auth/yt-analytics.readonly'),
            'watch_time' => $this->status('permission_required', 'Temps de visionnage via YouTube Analytics API.', 'https://www.googleapis.com/auth/yt-analytics.readonly'),
            'audience_growth' => $this->status('permission_required', 'Abonnements gagnés/perdus via YouTube Analytics API.', 'https://www.googleapis.com/auth/yt-analytics.readonly'),
            'messages' => $this->status($connected, 'Commentaires déjà synchronisés par le polling YouTube ELChat.'),
        ];
    }

    public function sync(SocialAccount $account, Carbon $from, Carbon $to): array
    {
        $result = $this->unavailablePayload($account, $from, $to);
        if (!$account->access_token) {
            $result['limitations'][] = 'Jeton YouTube absent.';
            return $result;
        }
        try {
            $playlist = $account->metadata['uploads_playlist_id'] ?? null;
            if (!$playlist) {
                $channelResponse = $this->channel->authorizedRequest($account)->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'contentDetails', 'id' => $account->provider_account_id]);
                $playlist = $channelResponse->json('items.0.contentDetails.relatedPlaylists.uploads');
                if ($playlist) $account->update(['metadata' => array_merge($account->metadata ?? [], ['uploads_playlist_id' => $playlist])]);
            }
            if (!$playlist) throw new \RuntimeException('Playlist uploads YouTube introuvable.');
            $maxContents = min(100, max(1, (int) config('social-intelligence.max_contents_per_provider', 100)));
            $ids = [];
            $pageToken = null;
            do {
                $params = ['part' => 'contentDetails', 'playlistId' => $playlist, 'maxResults' => min(50, $maxContents - count($ids))];
                if ($pageToken) $params['pageToken'] = $pageToken;
                $items = $this->channel->authorizedRequest($account)->get('https://www.googleapis.com/youtube/v3/playlistItems', $params);
                if (!$items->successful()) throw new \RuntimeException('Playlist uploads YouTube inaccessible.');
                $ids = [...$ids, ...collect($items->json('items', []))->map(fn ($item) => $item['contentDetails']['videoId'] ?? null)->filter()->values()->all()];
                $pageToken = $items->json('nextPageToken');
            } while ($pageToken && count($ids) < $maxContents);
            $ids = array_slice(array_values(array_unique($ids)), 0, $maxContents);
            if ($ids === []) return $result;
            foreach (array_chunk($ids, 50) as $batch) {
                $videos = $this->channel->authorizedRequest($account)->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $batch)]);
                foreach ($videos->json('items', []) as $item) {
                    $published = isset($item['snippet']['publishedAt']) ? Carbon::parse($item['snippet']['publishedAt']) : null;
                    if ($published && ($published->lt($from) || $published->gt($to))) continue;
                    $id = (string) ($item['id'] ?? '');
                    $result['contents'][] = [
                        'external_content_id' => $id, 'content_type' => 'video', 'title' => $item['snippet']['title'] ?? null,
                        'body' => $item['snippet']['description'] ?? null, 'permalink' => $id ? "https://www.youtube.com/watch?v={$id}" : null,
                        'published_at' => $published, 'metadata' => ['channel_id' => $item['snippet']['channelId'] ?? $account->provider_account_id, 'duration' => $item['contentDetails']['duration'] ?? null, 'tags' => $item['snippet']['tags'] ?? []],
                    ];
                    foreach (['viewCount' => ['views', 'Nombre de vues de la vidéo'], 'likeCount' => ['likes', 'Nombre de likes de la vidéo'], 'commentCount' => ['comments', 'Nombre de commentaires de la vidéo']] as $field => [$key, $definition]) {
                        if (isset($item['statistics'][$field]) && is_numeric($item['statistics'][$field])) $result['metrics'][] = $this->metric($id, $key, $definition, $item['statistics'][$field], false);
                    }
                }
            }
            $this->syncAnalytics($account, $from, $to, $result);
        } catch (\Throwable $exception) {
            Log::warning('Social Intelligence YouTube sync failed', ['account_id' => $account->id, 'error' => $exception->getMessage()]);
            $result['limitations'][] = 'Erreur lors de la récupération YouTube ; les données déjà synchronisées restent conservées.';
        }
        $result['interactions_seen'] = $this->interactions($account, $from, $to);
        return $result;
    }

    private function syncAnalytics(SocialAccount $account, Carbon $from, Carbon $to, array &$result): void
    {
        $analyticsScope = collect($account->metadata['granted_scopes'] ?? [])->contains(fn ($scope) => str_contains((string) $scope, 'yt-analytics.readonly'));
        if (!$analyticsScope) {
            $result['limitations'][] = 'YouTube Analytics non activé : les métriques watch time, shares et audience growth sont explicitement indisponibles.';
            return;
        }
        $response = $this->channel->authorizedRequest($account)->get('https://youtubeanalytics.googleapis.com/v2/reports', [
            'ids' => 'channel==' . $account->provider_account_id, 'startDate' => $from->toDateString(), 'endDate' => $to->toDateString(),
            'metrics' => 'views,likes,comments,shares,estimatedMinutesWatched,subscribersGained,subscribersLost', 'dimensions' => 'day', 'sort' => 'day',
        ]);
        if (!$response->successful()) {
            $result['limitations'][] = 'YouTube Analytics a refusé le rapport pour cette chaîne ou ces scopes.';
            return;
        }
        $headers = collect($response->json('columnHeaders', []))->pluck('name')->all();
        foreach ($response->json('rows', []) as $row) {
            $values = array_combine($headers, $row) ?: [];
            $day = isset($values['day']) ? Carbon::parse($values['day']) : now();
            foreach (['shares' => ['shares', 'Nombre de partages des vidéos'], 'estimatedMinutesWatched' => ['watch_time', 'Minutes estimées regardées'], 'subscribersGained' => ['subscribers_gained', 'Abonnements gagnés'], 'subscribersLost' => ['subscribers_lost', 'Abonnements perdus']] as $field => [$key, $definition]) {
                if (isset($values[$field]) && is_numeric($values[$field])) $result['metrics'][] = ['external_content_id' => null, 'metric_key' => $key, 'metric_definition' => $definition, 'value' => (float) $values[$field], 'unit' => $key === 'watch_time' ? 'minutes' : 'count', 'source' => 'youtube_analytics_api', 'comparable' => false, 'observed_at' => $day, 'period_from' => $day->copy()->startOfDay(), 'period_to' => $day->copy()->endOfDay()];
            }
        }
    }

    private function metric(string $contentId, string $key, string $definition, mixed $value, bool $comparable): array
    {
        return ['external_content_id' => $contentId, 'metric_key' => $key, 'metric_definition' => $definition, 'value' => (float) $value, 'unit' => 'count', 'source' => 'youtube_data_api', 'comparable' => $comparable, 'observed_at' => now()];
    }
}
