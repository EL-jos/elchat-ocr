<?php

namespace App\Observers;

use App\Enums\AnalyticsEventType;
use App\Models\UnansweredQuestion;
use App\Services\analytics\AnalyticsEventService;

class UnansweredQuestionObserver
{
    public function __construct(private readonly AnalyticsEventService $analytics)
    {
    }

    public function created(UnansweredQuestion $question): void
    {
        $site = $question->site;
        if (!$site) {
            return;
        }

        $this->analytics->capture(
            $site,
            AnalyticsEventType::UNANSWERED_QUESTION,
            [
                'resource_type' => 'unanswered_question',
                'resource_id' => $question->id,
                'source' => 'knowledge_retrieval',
            ],
            idempotencyKey: $this->analytics->deterministicKey('unanswered_question', $question->id),
        );
    }
}
