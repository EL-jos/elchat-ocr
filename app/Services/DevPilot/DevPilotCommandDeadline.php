<?php

namespace App\Services\DevPilot;

final class DevPilotCommandDeadline
{
    private float $lastActivityAt;

    public function __construct(
        private readonly float $startedAt,
        private readonly int $maximumSeconds,
        private readonly int $idleSeconds,
    ) {
        $this->lastActivityAt = $startedAt;
    }

    public function recordActivity(float $at): void
    {
        $this->lastActivityAt = $at;
    }

    public function totalExpired(float $at): bool
    {
        return $at - $this->startedAt >= $this->maximumSeconds;
    }

    public function idleExpired(float $at): bool
    {
        return $at - $this->lastActivityAt >= $this->idleSeconds;
    }

    public function expiredReason(float $at): ?string
    {
        if ($this->totalExpired($at)) return 'total';
        if ($this->idleExpired($at)) return 'idle';

        return null;
    }
}
