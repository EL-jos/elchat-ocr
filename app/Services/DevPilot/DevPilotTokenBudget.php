<?php

namespace App\Services\DevPilot;

use Illuminate\Support\Facades\Cache;

/** Records provider-reported token usage and enforces per-task and per-site daily limits. */
final class DevPilotTokenBudget
{
    /** @return array{plan:array, exceeded:bool, reason:?string, daily_total:int} */
    public function record(string|int $siteId, array $plan, ?array $providerUsage): array
    {
        $usage = $this->normalizeUsage($providerUsage);
        $totals = is_array($plan['token_usage'] ?? null)
            ? array_merge(['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], $plan['token_usage'])
            : ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        foreach ($totals as $key => $value) $totals[$key] = max(0, (int) $value);
        foreach ($usage as $key => $value) $totals[$key] += $value;
        $plan['token_usage'] = $totals;

        $dailyTotal = 0;
        if ($usage['total_tokens'] > 0) {
            $date = now()->toDateString();
            $key = 'devpilot:token-usage:site:'.(string) $siteId.':'.$date;
            Cache::add($key, 0, now()->addDays(2));
            $dailyTotal = (int) Cache::increment($key, $usage['total_tokens']);
        } else {
            $dailyTotal = (int) Cache::get('devpilot:token-usage:site:'.(string) $siteId.':'.now()->toDateString(), 0);
        }

        $reasons = [];
        if ($totals['total_tokens'] > max(1, (int) config('devpilot.task_token_budget', 200000))) $reasons[] = 'task';
        if ($dailyTotal > max(1, (int) config('devpilot.daily_token_budget_per_site', 1000000))) $reasons[] = 'daily_site';
        $exceeded = ($plan['token_budget_exceeded'] ?? false) === true || $reasons !== [];
        if ($exceeded) {
            $plan['token_budget_exceeded'] = true;
            $plan['token_budget_exceeded_reason'] = $reasons !== []
                ? implode(',', $reasons)
                : ($plan['token_budget_exceeded_reason'] ?? 'previously_exceeded');
        }

        return ['plan' => $plan, 'exceeded' => $exceeded, 'reason' => $exceeded ? ($plan['token_budget_exceeded_reason'] ?? 'previously_exceeded') : null, 'daily_total' => $dailyTotal];
    }

    /** @return array{prompt_tokens:int, completion_tokens:int, total_tokens:int} */
    private function normalizeUsage(?array $usage): array
    {
        $prompt = max(0, (int) ($usage['prompt_tokens'] ?? 0));
        $completion = max(0, (int) ($usage['completion_tokens'] ?? 0));
        $total = max(0, (int) ($usage['total_tokens'] ?? ($prompt + $completion)));

        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $total];
    }
}
