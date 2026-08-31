<?php

namespace App\Services;

use App\Domain\Microsoft365\Exceptions\MicrosoftGraphException;
use App\Domain\Microsoft365\MicrosoftGraphClient;
use App\Models\Document;
use App\Models\Mcp\Microsoft365Source;
use App\Models\Mcp\Microsoft365SyncCursor;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Site;
use App\Jobs\document\IndexDocumentJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class Microsoft365SyncService
{
    private const INDEXABLE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];

    public function __construct(private readonly DocumentLifecycleService $lifecycle)
    {
    }

    /** @return array<string, int|string|null> */
    public function sync(Site $site, array $credentials, ?string $driveId = null, ?string $providerSiteId = null, bool $resetExpiredCursor = true): array
    {
        $graph = MicrosoftGraphClient::forToken((string) ($credentials['access_token'] ?? ''));
        $connection = $site->mcpSiteConnectors()->whereHas('mcpConnector', fn ($q) => $q->where('slug', 'microsoft_365'))->first();
        $driveKey = $driveId ?: ($providerSiteId ? 'site:' . $providerSiteId : 'me');
        $prefix = $driveId
            ? '/drives/' . rawurlencode($driveId)
            : ($providerSiteId ? '/sites/' . rawurlencode($providerSiteId) . '/drive' : '/me/drive');
        $cursorSiteId = (string) ($providerSiteId ?? '');

        $cursor = Microsoft365SyncCursor::firstOrCreate(
            ['site_id' => $site->id, 'provider_drive_id' => $driveKey, 'provider_site_id' => $cursorSiteId],
            ['provider_tenant_id' => $connection?->provider_tenant_id]
        );

        $next = $cursor->delta_link ?: $prefix . '/root/delta';
        $seen = 0;
        $indexed = 0;
        $deleted = 0;
        $pages = 0;
        $deltaLink = null;

        do {
            try {
                $response = $graph->get($next, $pages === 0 && !$cursor->delta_link ? [
                    '$select' => 'id,name,size,file,folder,parentReference,webUrl,eTag,lastModifiedDateTime,createdDateTime,@removed',
                ] : []);
            } catch (MicrosoftGraphException $exception) {
                // Delta links expire. Clearing the cursor and replaying a full
                // delta is safe because documents are keyed by external_id and
                // IndexDocumentJob ignores stale revisions.
                if ($exception->status === 410 && $cursor->delta_link && $resetExpiredCursor) {
                    $cursor->delta_link = null;
                    $cursor->last_error = 'delta_cursor_expired';
                    $cursor->save();

                    return $this->sync($site, $credentials, $driveId, $providerSiteId, false);
                }

                throw $exception;
            }
            $pages++;

            foreach ($response['value'] ?? [] as $item) {
                $seen++;
                if (isset($item['@removed'])) {
                    $this->removeItem($site, (string) ($item['id'] ?? ''), $driveKey);
                    $deleted++;
                    continue;
                }
                if (!isset($item['id'])) continue;
                if (isset($item['folder'])) continue;
                if (!isset($item['file'])) continue;

                $indexed += $this->syncFile($site, $item, $driveKey, $providerSiteId, $connection, (string) $credentials['access_token']) ? 1 : 0;
            }

            $next = $response['@odata.nextLink'] ?? null;
            $deltaLink = $response['@odata.deltaLink'] ?? $deltaLink;
        } while ($next && $pages < 100);

        if ($deltaLink) {
            $cursor->delta_link = $deltaLink;
        }
        $cursor->provider_tenant_id = $connection?->provider_tenant_id;
        $cursor->last_synced_at = now();
        $cursor->last_error = null;
        $cursor->save();

        return ['seen' => $seen, 'indexed' => $indexed, 'deleted' => $deleted, 'pages' => $pages, 'cursor_id' => $cursor->id];
    }

    private function syncFile(Site $site, array $item, string $driveKey, ?string $providerSiteId, ?McpSiteConnector $connection, string $accessToken): bool
    {
        $name = (string) ($item['name'] ?? '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $source = Microsoft365Source::updateOrCreate(
            ['site_id' => $site->id, 'provider_drive_id' => $driveKey, 'provider_item_id' => $item['id']],
            [
                'provider_tenant_id' => $connection?->provider_tenant_id,
                'provider_principal_id' => $connection?->provider_principal_id,
                'provider_site_id' => $providerSiteId,
                'name' => $name ?: 'Microsoft 365 file',
                'mime_type' => $item['file']['mimeType'] ?? null,
                'web_url' => $item['webUrl'] ?? null,
                'etag' => $item['eTag'] ?? null,
                'permissions' => ['delegated_principal_id' => $connection?->provider_principal_id, 'tenant_id' => $connection?->provider_tenant_id],
                'status' => in_array($extension, self::INDEXABLE_EXTENSIONS, true) ? 'active' : 'skipped',
                'last_seen_at' => now(),
                'last_error' => null,
            ]
        );

        if (!in_array($extension, self::INDEXABLE_EXTENSIONS, true)) {
            return false;
        }

        $document = Document::query()
            ->where('documentable_type', Site::class)
            ->where('documentable_id', $site->id)
            ->where('origin', 'microsoft_365')
            ->where('external_id', $item['id'])
            ->first();

        if ($document && $document->external_etag === ($item['eTag'] ?? null) && $document->indexing_status === 'indexed') {
            return false;
        }

        // The Graph client returns bytes directly; they are stored on the
        // private local disk, never below public/ and never exposed as a URL.
        if ($accessToken === '') return false;
        $prefix = $driveKey === 'me'
            ? '/me/drive'
            : ($providerSiteId && str_starts_with($driveKey, 'site:')
                ? '/sites/' . rawurlencode($providerSiteId) . '/drive'
                : '/drives/' . rawurlencode($driveKey));
        try {
            $content = MicrosoftGraphClient::forToken($accessToken)->download($prefix . '/items/' . rawurlencode($item['id']) . '/content');
        } catch (MicrosoftGraphException $exception) {
            if ($exception->isAuthFailure()) {
                throw $exception;
            }

            $source->update(['status' => 'error', 'last_error' => 'download_failed']);
            return false;
        } catch (\Throwable $exception) {
            $source->update(['status' => 'error', 'last_error' => 'download_failed']);
            return false;
        }

        if (strlen($content) > 50 * 1024 * 1024) {
            $source->update(['status' => 'skipped', 'last_error' => 'file_too_large_for_indexing']);
            return false;
        }

        $storagePath = 'microsoft365/' . $site->id . '/' . Str::uuid() . '.' . $extension;
        Storage::disk('local')->put($storagePath, $content);
        $oldStoragePath = $document?->storage_path;
        $revision = ((int) ($document?->index_revision ?? 0)) + 1;

        $attributes = [
            'type' => 'file', 'origin' => 'microsoft_365', 'path' => $storagePath,
            'storage_disk' => 'local', 'storage_path' => $storagePath,
            'extension' => $extension, 'original_name' => $name, 'title' => $name,
            'mime_type' => $item['file']['mimeType'] ?? null, 'file_size' => strlen($content),
            'indexing_status' => 'pending', 'index_revision' => $revision,
            'last_indexed_at' => null, 'indexing_error' => null,
            'external_id' => $item['id'], 'external_drive_id' => $driveKey,
            'external_site_id' => $providerSiteId, 'external_etag' => $item['eTag'] ?? null,
            'external_web_url' => $item['webUrl'] ?? null,
            'access_tenant_id' => $connection?->provider_tenant_id,
            'access_principal_id' => $connection?->provider_principal_id,
            'source_metadata' => ['source' => 'microsoft_365', 'permissions' => $source->permissions],
            'priority' => 0,
        ];

        if ($document) {
            $document->update($attributes);
        } else {
            $document = $site->documents()->create($attributes);
        }

        if ($oldStoragePath && $oldStoragePath !== $storagePath) {
            Storage::disk('local')->delete($oldStoragePath);
        }

        IndexDocumentJob::dispatch($document->fresh(), $site, $revision);
        return true;
    }

    private function removeItem(Site $site, string $itemId, string $driveKey): void
    {
        if ($itemId === '') return;
        $document = Document::query()
            ->where('documentable_type', Site::class)
            ->where('documentable_id', $site->id)
            ->where('origin', 'microsoft_365')
            ->where('external_id', $itemId)
            ->first();
        if ($document) $this->lifecycle->deleteDocument($document, $site);
        Microsoft365Source::query()->where('site_id', $site->id)->where('provider_drive_id', $driveKey)->where('provider_item_id', $itemId)->update(['status' => 'deleted', 'last_seen_at' => now()]);
    }
}
