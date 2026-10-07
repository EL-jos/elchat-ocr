<?php

namespace App\SocialIntelligence\Contracts;

use App\Models\Social\SocialAccount;
use Illuminate\Support\Carbon;

interface SocialIntelligenceProviderInterface
{
    public function provider(): string;

    /** @return array<string, array{status:string, reason:?string, definition:string}> */
    public function capabilities(SocialAccount $account): array;

    /**
     * @return array{contents:array<int,array<string,mixed>>,metrics:array<int,array<string,mixed>>,limitations:array<int,string>,interactions_seen:int}
     */
    public function sync(SocialAccount $account, Carbon $from, Carbon $to): array;
}
