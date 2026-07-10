<?php

namespace App\Services\Social\YoutTube;

use App\Jobs\social\ProcessYouTubeCommentEventJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialEvent;
use App\SocialChannels\YouTube\YouTubeChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeChannelSyncService
{
    private string $baseUrl;

    public function __construct(
        private YouTubeChannel $youtubeChannel,
    ) {
        $this->baseUrl = 'https://www.googleapis.com/youtube/v3';
    }

    public function sync(SocialAccount $account): void
    {
        // ✅ Capturer l'heure de DÉBUT du sync — devient le nouveau curseur
        // si tout se déroule bien (évite de perdre des commentaires postés
        // PENDANT le sync)
        $syncStartedAt = now();
        $cursor = $this->getCursor($account);

        $uploadsPlaylistId = $this->resolveUploadsPlaylistId($account);

        $videos = $this->fetchRecentVideos($account, $uploadsPlaylistId);

        $stats = ['videos' => 0, 'comments' => 0, 'replies' => 0];

        foreach ($videos as $video) {
            $result = $this->syncVideoComments($account, $video['id'], $cursor);

            $stats['videos']++;
            $stats['comments'] += $result['comments'];
            $stats['replies']  += $result['replies'];
        }

        $this->updateCursor($account, $syncStartedAt);

        Log::info('[YouTube] Sync terminé', [
            'account_id' => $account->id,
            ...$stats,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // CURSOR — last_comment_sync_at
    // ─────────────────────────────────────────────────────────

    private function getCursor(SocialAccount $account): ?\Illuminate\Support\Carbon
    {
        $value = $account->metadata['last_comment_sync_at'] ?? null;

        return $value ? \Illuminate\Support\Carbon::parse($value) : null;
    }

    private function updateCursor(SocialAccount $account, \Illuminate\Support\Carbon $syncStartedAt): void
    {
        $metadata = $account->metadata ?? [];

        $metadata['last_comment_sync_at'] = $syncStartedAt->toIso8601String();

        $account->update(['metadata' => $metadata]);
    }

    // ─────────────────────────────────────────────────────────
    // VIDEOS — via uploads playlist (1 unité au lieu de 100)
    // ─────────────────────────────────────────────────────────

    /**
     * channels.list (1 unité, mise en cache dans metadata)
     * → récupère l'ID de la playlist "uploads" de la chaîne,
     *   qui contient TOUTES ses vidéos triées du plus récent au plus ancien.
     */
    private function resolveUploadsPlaylistId(SocialAccount $account): string
    {
        $metadata = $account->metadata ?? [];

        if (!empty($metadata['uploads_playlist_id'])) {
            return $metadata['uploads_playlist_id'];
        }

        $response = $this->youtubeChannel
            ->authorizedRequest($account)
            ->get("{$this->baseUrl}/channels", [
                'part' => 'contentDetails',
                'id'   => $account->provider_account_id,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Impossible de récupérer la playlist uploads: " . $response->body()
            );
        }

        $playlistId = $response->json('items.0.contentDetails.relatedPlaylists.uploads');

        if (!$playlistId) {
            throw new \RuntimeException(
                "Playlist 'uploads' introuvable pour le channel {$account->provider_account_id}"
            );
        }

        $metadata['uploads_playlist_id'] = $playlistId;
        $account->update(['metadata' => $metadata]);

        return $playlistId;
    }

    /**
     * playlistItems.list (1 unité) → bien moins coûteux que search.list (100 unités)
     */
    private function fetchRecentVideos(SocialAccount $account, string $uploadsPlaylistId): array
    {
        $maxResults = config('services.youtube.videos_per_sync', 25);

        $response = $this->youtubeChannel
            ->authorizedRequest($account)
            ->get("{$this->baseUrl}/playlistItems", [
                'part'       => 'contentDetails',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => $maxResults,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Impossible de récupérer les vidéos: ' . $response->body());
        }

        return collect($response->json('items', []))
            ->map(fn ($item) => [
                'id'           => $item['contentDetails']['videoId'],
                'published_at' => $item['contentDetails']['videoPublishedAt'] ?? null,
            ])
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────
    // COMMENTAIRES — avec pagination + early-exit via curseur
    // ─────────────────────────────────────────────────────────

    private function syncVideoComments(
        SocialAccount $account,
        string $videoId,
        ?\Illuminate\Support\Carbon $cursor,
    ): array {

        $stats     = ['comments' => 0, 'replies' => 0];
        $pageToken = null;
        $page      = 0;
        $maxPages  = config('services.youtube.max_comment_pages', 20); // garde-fou 1er sync

        do {
            $response = $this->youtubeChannel
                ->authorizedRequest($account)
                ->get("{$this->baseUrl}/commentThreads", array_filter([
                    'part'       => 'snippet,replies',
                    'videoId'    => $videoId,
                    'maxResults' => 100,
                    'order'      => 'time', // ✅ plus récent en premier
                    'textFormat' => 'plainText',
                    'pageToken'  => $pageToken,
                ]));

            // ✅ Commentaires désactivés sur cette vidéo → on log et on passe
            if ($response->status() === 403) {
                Log::info('[YouTube] Commentaires désactivés ou inaccessibles', [
                    'account_id' => $account->id,
                    'video_id'   => $videoId,
                ]);
                return $stats;
            }

            if (!$response->successful()) {
                Log::warning('[YouTube] Échec récupération commentaires', [
                    'account_id' => $account->id,
                    'video_id'   => $videoId,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return $stats;
            }

            $data = $response->json();
            $stop = false;

            foreach ($data['items'] ?? [] as $thread) {

                $topSnippet   = $thread['snippet']['topLevelComment']['snippet'];
                $topCommentId = $thread['snippet']['topLevelComment']['id'];
                $publishedAt  = \Illuminate\Support\Carbon::parse($topSnippet['publishedAt']);

                $created = $this->storeComment($account, $videoId, [
                    'comment_id'           => $topCommentId,
                    'top_level_comment_id' => $topCommentId,
                    'parent_comment_id'    => null,
                    'author_channel_id'    => $topSnippet['authorChannelId']['value'] ?? null,
                    'author_name'          => $topSnippet['authorDisplayName'] ?? null,
                    'message'              => $topSnippet['textOriginal'] ?? $topSnippet['textDisplay'],
                    'published_at'         => $topSnippet['publishedAt'],
                    'updated_at'           => $topSnippet['updatedAt'] ?? $topSnippet['publishedAt'],
                    'raw'                  => $thread,
                ]);

                if ($created) {
                    $stats['comments']++;
                } elseif ($cursor && $publishedAt->lessThanOrEqualTo($cursor)) {
                    // ✅ Déjà connu ET plus ancien que le curseur
                    // → tout ce qui suit (order=time) l'est aussi → on arrête
                    $stop = true;
                }

                // ✅ Réponses (toujours 1 seul niveau sous le top-level sur YouTube)
                $stats['replies'] += $this->syncCommentReplies(
                    $account, $videoId, $topCommentId, $thread, $cursor
                );
            }

            $pageToken = $data['nextPageToken'] ?? null;
            $page++;

            if ($page >= $maxPages) {
                Log::warning('[YouTube] Limite de pages atteinte pour une vidéo', [
                    'account_id' => $account->id,
                    'video_id'   => $videoId,
                    'pages'      => $page,
                ]);
                break;
            }

        } while ($pageToken && !$stop);

        return $stats;
    }

    /**
     * Récupère TOUTES les réponses d'un thread, pas seulement
     * les ~5 incluses dans commentThreads.list.
     */
    private function syncCommentReplies(
        SocialAccount $account,
        string $videoId,
        string $topLevelCommentId,
        array $thread,
        ?\Illuminate\Support\Carbon $cursor,
    ): int {

        $totalReplyCount = $thread['snippet']['totalReplyCount'] ?? 0;
        $inlineReplies   = $thread['replies']['comments'] ?? [];

        // ✅ Si toutes les réponses sont déjà incluses inline, pas d'appel supplémentaire
        $replies = (count($inlineReplies) >= $totalReplyCount)
            ? $inlineReplies
            : $this->fetchAllReplies($account, $topLevelCommentId);

        $count = 0;

        foreach ($replies as $reply) {
            $snippet = $reply['snippet'];

            $created = $this->storeComment($account, $videoId, [
                'comment_id'           => $reply['id'],
                'top_level_comment_id' => $topLevelCommentId, // ✅ requis par sendReply()
                'parent_comment_id'    => $topLevelCommentId,
                'author_channel_id'    => $snippet['authorChannelId']['value'] ?? null,
                'author_name'          => $snippet['authorDisplayName'] ?? null,
                'message'              => $snippet['textOriginal'] ?? $snippet['textDisplay'],
                'published_at'         => $snippet['publishedAt'],
                'updated_at'           => $snippet['updatedAt'] ?? $snippet['publishedAt'],
                'raw'                  => $reply,
            ]);

            if ($created) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * comments.list?parentId=... (1 unité/page) — fallback pour récupérer
     * TOUTES les réponses si elles dépassent celles incluses inline.
     */
    private function fetchAllReplies(SocialAccount $account, string $topLevelCommentId, int $maxPages = 5): array
    {
        $replies   = [];
        $pageToken = null;
        $page      = 0;

        do {
            $response = $this->youtubeChannel
                ->authorizedRequest($account)
                ->get("{$this->baseUrl}/comments", array_filter([
                    'part'       => 'snippet',
                    'parentId'   => $topLevelCommentId,
                    'maxResults' => 100,
                    'textFormat' => 'plainText',
                    'pageToken'  => $pageToken,
                ]));

            if (!$response->successful()) {
                break;
            }

            $data    = $response->json();
            $replies = array_merge($replies, $data['items'] ?? []);
            $pageToken = $data['nextPageToken'] ?? null;
            $page++;

        } while ($pageToken && $page < $maxPages);

        return $replies;
    }

    // ─────────────────────────────────────────────────────────
    // STOCKAGE
    // ─────────────────────────────────────────────────────────

    /**
     * @return bool true si un nouveau SocialEvent a été créé
     */
    private function storeComment(SocialAccount $account, string $videoId, array $data): bool
    {
        $hash = hash('sha256', $account->id . ':' . $data['comment_id']);

        $exists = SocialEvent::query()
            ->where('external_event_id', $hash)
            ->exists();

        if ($exists) {
            return false;
        }

        $event = SocialEvent::create([
            'social_account_id'  => $account->id,
            'provider'           => 'youtube',
            'event_type'         => 'comment_received',
            'external_event_id'  => $hash,
            'processing_status'  => 'pending',
            'payload' => [
                'video_id'             => $videoId,
                'comment_id'           => $data['comment_id'],
                'top_level_comment_id' => $data['top_level_comment_id'],
                'parent_comment_id'    => $data['parent_comment_id'],
                'author_channel_id'    => $data['author_channel_id'],
                'author_name'          => $data['author_name'],
                'message'              => $data['message'],
                'published_at'         => $data['published_at'],
                'updated_at'           => $data['updated_at'],
                'raw'                  => $data['raw'],
            ],
        ]);

        // ✅ NOUVEAU — déclenche le parsing/conversion en SocialConversation/SocialMessage
        ProcessYouTubeCommentEventJob::dispatch($event->id);

        return true;
    }
}
