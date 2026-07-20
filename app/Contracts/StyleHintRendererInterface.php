<?php

namespace App\Contracts;

use App\Enums\ConversationPace;
use App\Enums\ResponseDepth;
use App\Models\Site;

interface StyleHintRendererInterface
{
    /**
     * @return string[] fragments qualitatifs courts, jamais de règles numériques
     */
    public function render(
        ResponseDepth $depth,
        ConversationPace $pace,
        bool $shouldOfferClarifyingQuestion,
        bool $suppressStockClosings,
        Site $site,
        ?string $roleSlug,
    ): array;
}
