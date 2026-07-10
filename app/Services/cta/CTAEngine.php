<?php

namespace App\Services\cta;

use App\Models\ChatbotCta;
use App\Models\Conversation;
use App\Models\MessageCTA;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;


class CTAEngine
{
    protected array $matchers = [];

    public function __construct(
        protected CtaRepository $repository
    ) {
        $this->matchers = collect(config('cta.matchers', []))
            ->map(function ($class) {
                if (!class_exists($class)) {
                    throw new \Exception("CTA Matcher not found: {$class}");
                }

                return app($class);
            })
            ->toArray();
    }

    public function resolve(
        Site $site,
        QueryPlan $queryPlan,
        Conversation $conversation
    ): array {

        $ctas = $this->repository->getActiveForSite($site->id);

        $scored = [];

        foreach ($ctas as $cta) {

            // ─────────────────────────────
            // 🚫 Anti-spam CTA (max_display)
            // ─────────────────────────────
            if ($cta->max_display) {

                $count = MessageCTA::where('cta_id', $cta->id)
                    ->whereHas('message', function ($q) use ($conversation) {
                        $q->where('conversation_id', $conversation->id);
                    })
                    ->count();

                if ($count >= $cta->max_display) {
                    continue; // ❌ on skip ce CTA
                }
            }

            // ─────────────────────────────
            // 🎯 Scoring normal
            // ─────────────────────────────
            $globalScore = new ScoreResult();

            foreach ($this->matchers as $matcher) {

                $score = $matcher->score($cta, $queryPlan, $conversation);

                $globalScore->merge($score);
            }

            if ($globalScore->score > 0) {
                $scored[] = [
                    'cta' => $cta,
                    'score' => $globalScore->score,
                    'reasons' => $globalScore->reasons
                ];
            }
        }

        return collect($scored)
            ->sortByDesc('score')
            ->take(config('cta.limit', 3))
            ->map(function ($item) {
                $ctaArray = CtaResource::make($item['cta']);

                // 🔥 enrichissement propre
                $ctaArray['score'] = $item['score'];

                if (!app()->environment('production')) {
                    $ctaArray['debug'] = $item['reasons'];
                }

                return $ctaArray;
            })
            ->values()
            ->toArray();
    }

    public function setMatchers(array $matchers): void
    {
        $this->matchers = $matchers;
    }
}
