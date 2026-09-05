<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Site;
use Illuminate\Http\Request;

trait AuthorizesSiteAccess
{
    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        $accountId = $request->user()?->ownedAccount?->id;
        abort_unless($accountId && $site->account_id === $accountId, 403);
    }
}
