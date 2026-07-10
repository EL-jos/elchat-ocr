<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Jobs\sitemap\ProcessSitemapJob;
use App\Models\Document;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function store(Request $request, Site $site)
    {
        $this->authorizeSite($site);

        $request->validate([
            'sitemap_file' => 'required|file|mimes:xml,txt|max:2048'
        ]);

        $sitemap = null;

        if ($request->hasFile('sitemap_file')) {
            $files = $request->file('sitemap_file');
            $sitemap = $this->saveDocument($files, $site, 'file');
        }
        
        ProcessSitemapJob::dispatch(
            siteId: $site->id,
            sitemapDocumentId: $sitemap->id
        );

        $site->update([
            'status' => 'crawling',
        ]);


        return response()->json([
            'message' => 'Sitemap uploaded. Processing started.'
        ]);
    }

    private function authorizeSite(Site $site)
    {
        if ($site->account_id !== auth()->user()->ownedAccount->id) {
            abort(403);
        }
    }

    private function moveImage($file)
    {
        $currentDateTime = Carbon::now();
        $formattedDateTime = $currentDateTime->format('Ymd_His');

        $path_file = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('assets/sitemaps/'), $path_file);

        return "assets/sitemaps/" . $path_file;
    }
    private function deleteImage($path)
    {
        if ( file_exists( public_path($path) ) ) {
            unlink(public_path($path));
        }
    }
    private function saveDocument($files, Site $site, string $type){

        $file_path = null;
        if (is_array($files)) {

            foreach ($files as $file) {
                $documentPath = $this->moveImage($file);
                $document = new Document(['id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type]);
                $file_path = $site->documents()->save($document);
            }

        } else {

            $documentPath = $this->moveImage($files);
            $document = new Document(['id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type]);
            $file_path = $site->documents()->save($document);

        }

        return $file_path;
    }
}

