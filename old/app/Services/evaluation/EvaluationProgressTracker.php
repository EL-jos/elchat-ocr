<?php

namespace App\Services\evaluation;

use App\Services\MercureService;

class EvaluationProgressTracker
{
    public function __construct(
        protected MercureService $mercureService
    ) {}

    public function publish(string $siteId, array $payload): void
    {
        $this->mercureService->post(
            "site/{$siteId}/knowledge/indexing",
            $payload
        );
    }

    public function step(string $siteId, string $step, int $progress, string $message): void
    {
        $this->publish($siteId, [
            'type' => 'evaluation_progress',
            'step' => $step,
            'progress' => $progress,
            'message' => $message,
            'done' => false
        ]);
    }

    public function done(string $siteId, string $message = 'Terminé'): void
    {
        $this->publish($siteId, [
            'type' => 'evaluation_progress',
            'step' => 'done',
            'progress' => 100,
            'message' => $message,
            'done' => true
        ]);
    }

    public function error(string $siteId, string $message): void
    {
        $this->publish($siteId, [
            'type' => 'evaluation_error',
            'message' => $message,
            'done' => true
        ]);
    }
}
