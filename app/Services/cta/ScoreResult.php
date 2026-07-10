<?php

namespace App\Services\cta;

class ScoreResult
{
    public function __construct(
        public int $score = 0,
        public array $reasons = []
    ) {}

    public function add(int $points, string $reason): void
    {
        $this->score += $points;
        $this->reasons[] = [
            'points' => $points,
            'reason' => $reason
        ];
    }

    public function merge(ScoreResult $other): void
    {
        $this->score += $other->score;
        $this->reasons = array_merge($this->reasons, $other->reasons);
    }
}
