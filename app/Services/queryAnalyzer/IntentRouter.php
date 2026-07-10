<?php

namespace App\Services\queryAnalyzer;

use App\Models\Site;
use Illuminate\Support\Facades\Log;

class IntentRouter
{
    public function route(QueryPlan $plan, Site $site): string
    {
        $siteType = $site->type->slug;

        $capabilityKey =
            config("chatbot_intents.site_types.$siteType")
            ?? config("chatbot_intents.default_capability");

        $allowedIntents =
            config("chatbot_intents.capabilities.$capabilityKey");

        Log::info("DANS Intents Router", [
            "Tye de site" => $siteType,
            "Intents" => $allowedIntents,
            "Plan intents" => $plan->intent,
        ]);

        if (!in_array($plan->intent, $allowedIntents)) {
            return "information_rag";
        }

        return match($plan->intent) {

            'pricing' => 'pricing_rag',

            'comparison' => 'comparison_rag',

            'support' => 'support_rag',

            'lead' => 'lead_capture',

            'navigation' => 'navigation',

            'transactional' => 'transaction_flow',

            default => 'information_rag',
        };
    }
}
