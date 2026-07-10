<?php

namespace App\Interfaces;

use App\Models\ChatbotCta;
use App\Models\Conversation;
use App\Services\cta\ScoreResult;
use App\Services\queryAnalyzer\QueryPlan;

interface CtaRuleMatcher
{
    public function score(ChatbotCta $cta, QueryPlan $queryPlan, Conversation $conversation): ScoreResult;
}
