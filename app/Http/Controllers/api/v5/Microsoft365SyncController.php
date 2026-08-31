<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\Microsoft365SyncJob;
use App\Models\Mcp\Microsoft365Source;
use App\Models\Site;
use Illuminate\Http\Request;

class Microsoft365SyncController extends Controller
{
    use AuthorizesSiteAccess;

    public function index(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => Microsoft365Source::where('site_id', $site->id)->orderBy('name')->paginate(50)]);
    }

    public function sync(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        $validated = $request->validate([
            'drive_id' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'string', 'max:255'],
        ]);
        Microsoft365SyncJob::dispatch($site, $validated['drive_id'] ?? null, $validated['site_id'] ?? null);
        return response()->json(['status' => 'queued']);
    }
}
