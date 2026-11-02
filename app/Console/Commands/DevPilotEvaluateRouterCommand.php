<?php

namespace App\Console\Commands;

use App\Services\DevPilot\DevPilotIntentPlanner;
use App\Services\DevPilot\DevPilotToolRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Measures the real intent planner (live model call) against a bilingual case
 * file. Unit tests prove the validators; this proves the model understands
 * requests. Run it before changing the router model, prompt or need catalogue.
 */
final class DevPilotEvaluateRouterCommand extends Command
{
    protected $signature = 'devpilot:eval-router
        {--file=tests/Fixtures/devpilot_router_cases.json : JSON file of routing cases}
        {--model= : Override DEVPILOT_TOOL_ROUTER_MODEL for this run}
        {--min-pass=0.9 : Minimum pass ratio (0-1) for a zero exit code}
        {--only= : Run only cases whose id contains this text}';

    protected $description = 'Evaluate the DevPilot intent planner against real model output (bilingual routing cases).';

    public function handle(DevPilotIntentPlanner $planner, DevPilotToolRegistry $registry): int
    {
        $path = base_path((string) $this->option('file'));
        $cases = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($cases) || $cases === []) {
            $this->error('Case file not found or invalid: '.$path);
            return self::FAILURE;
        }
        if (is_string($this->option('model')) && $this->option('model') !== '') {
            config(['devpilot.tool_router.model' => $this->option('model')]);
        }

        $vocabulary = $registry->vocabulary();
        $passed = 0;
        $total = 0;
        foreach ($cases as $case) {
            if (! is_array($case) || ! is_string($case['phrase'] ?? null)) continue;
            $id = (string) ($case['id'] ?? $case['phrase']);
            if (is_string($this->option('only')) && $this->option('only') !== '' && ! str_contains($id, $this->option('only'))) continue;
            $total++;

            try {
                $result = $planner->plan($case['phrase'], [], $vocabulary['domains'], $vocabulary['needs']);
                $failures = $this->failures($case, $result);
            } catch (Throwable $exception) {
                $failures = ['planner error: '.$exception::class];
            }

            if ($failures === []) {
                $passed++;
                $this->line('<info>PASS</info> '.$id);
            } else {
                $this->line('<error>FAIL</error> '.$id.' — '.implode('; ', $failures));
            }
        }

        $ratio = $total === 0 ? 0.0 : $passed / $total;
        $this->newLine();
        $this->info(sprintf('%d/%d cases passed (%.0f%%) with model %s', $passed, $total, $ratio * 100, (string) config('devpilot.tool_router.model')));

        return $ratio >= (float) $this->option('min-pass') ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function failures(array $case, array $result): array
    {
        $plan = $result['plan'];
        if ($plan === null) return ['plan rejected: '.implode(' | ', array_slice($result['errors'] ?? [], 0, 3))];

        $failures = [];
        if (isset($case['mode']) && $plan['mode'] !== $case['mode']) $failures[] = 'mode '.$plan['mode'].' != '.$case['mode'];
        foreach ($case['required_needs'] ?? [] as $need) {
            if (! in_array($need, $plan['needs'], true)) $failures[] = 'missing need '.$need;
        }
        foreach ($case['forbidden_needs'] ?? [] as $need) {
            if (in_array($need, $plan['needs'], true)) $failures[] = 'forbidden need '.$need;
        }
        foreach ($case['forbidden_domains'] ?? [] as $domain) {
            if (in_array($domain, $plan['domains'], true)) $failures[] = 'forbidden domain '.$domain;
        }
        foreach ($case['contract_output'] ?? [] as $flag => $expected) {
            if (($result['contract']['output'][$flag] ?? null) !== $expected) $failures[] = 'contract.'.$flag.' should be '.($expected ? 'true' : 'false');
        }
        foreach ($case['contract_plugins'] ?? [] as $mention) {
            $found = array_filter($result['contract']['targets']['plugins'] ?? [], static fn (string $m): bool => mb_stripos($m, $mention) !== false);
            if ($found === []) $failures[] = 'plugin mention "'.$mention.'" missing from contract';
        }
        if (isset($case['contract_content_id']) && ($result['contract']['targets']['content']['id'] ?? null) !== $case['contract_content_id']) {
            $failures[] = 'contract content id should be '.$case['contract_content_id'];
        }
        if (isset($case['language']) && ($result['contract']['language'] ?? null) !== $case['language']) $failures[] = 'language should be '.$case['language'];

        return $failures;
    }
}
