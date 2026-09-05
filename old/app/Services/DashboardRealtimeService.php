<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardRealtimeService
{
    public function __construct(private readonly MercureService $mercure)
    {
    }

    public function publish(string $siteId, string $type, array $payload = [], array $topics = ['dashboard']): void
    {
        if ($siteId === '') {
            return;
        }

        $data = [
            ...$payload,
            'type' => $type,
            'site_id' => $siteId,
            'occurred_at' => now()->toISOString(),
        ];

        foreach (array_unique($topics) as $topic) {
            $topic = trim($topic, '/');
            if ($topic === '') {
                continue;
            }

            try {
                $this->mercure->post("/sites/{$siteId}/{$topic}", $data);
            } catch (Throwable $exception) {
                // Mercure doit rester une notification best-effort et ne doit
                // pas faire échouer l'enregistrement métier ou analytique.
                Log::warning('Dashboard realtime publication failed without affecting the product flow.', [
                    'site_id' => $siteId,
                    'topic' => $topic,
                    'type' => $type,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
