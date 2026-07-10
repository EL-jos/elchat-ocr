<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Jobs\PageImportJob;
use App\Jobs\RecrawlSinglePageJob;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Page;
use App\Models\Site;
use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

class PageController extends Controller
{

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Page $page, VectorIndexService $vectorIndexService, LexicalIndexService $lexicalIndexService)
    {

        DB::transaction(function () use (&$page, &$vectorIndexService) {


            // 1️⃣ Récupérer les chunks liés
            $chunkIds = $page->chunks()->pluck('id')->toArray();

            // 2️⃣ Supprimer les vecteurs dans Qdrant (non bloquant)
            $vectorIndexService->deleteChunksBatch($chunkIds, "chunks_{$page->site_id}");

            // 3️⃣ Supprimer les chunks en base
            $page->chunks()->delete();

            // 4️⃣ Supprimer la page
            $page->delete();
        });


        return response()->json([
            'message' => 'Page deleted successfully'
        ]);
    }

    public function destroyMultiple(Request $request, VectorIndexService $vectorIndexService, LexicalIndexService $lexicalIndexService)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return response()->json([
                'message' => 'No pages selected'
            ], 400);
        }

        $page = Page::where('id', $ids[0])->first();
        $site = $page->site;

        DB::transaction(function () use ($ids, $vectorIndexService, $lexicalIndexService, &$site) {

            // 1️⃣ Récupérer toutes les pages
            $pages = Page::whereIn('id', $ids)->get();

            // 2️⃣ Récupérer tous les chunk IDs liés
            $chunkIds = Chunk::whereIn('page_id', $ids)
                ->pluck('id')
                ->toArray();

            // 3️⃣ Supprimer les pages (chunks supprimés après)
            Page::whereIn('id', $ids)->delete();

            // 4️⃣ Supprimer les chunks en base
            Chunk::whereIn('page_id', $ids)->delete();

            // 5️⃣ Supprimer les vecteurs après commit
            DB::afterCommit(function () use ($chunkIds, $vectorIndexService, $lexicalIndexService, &$site) {
                $vectorIndexService->deleteChunksBatch($chunkIds, "chunks_{$site->id}");
                $lexicalIndexService->deleteChunksBatch(chunkIds: $chunkIds, siteId: $site->id);
            });

        });

        return response()->json([
            'message' => 'Pages deleted successfully'
        ]);
    }


    public function recrawl(Page $page)
    {
        $site = $page->site;

        // 🔐 Sécurité account
        if ($site->account_id !== auth()->user()->ownedAccount->id) {
            abort(403);
        }

        // 🔄 On passe en processing immédiatement
        $site->update([
            'status' => 'crawling'
        ]);

        RecrawlSinglePageJob::dispatch(
            pageId: $page->id,
            siteId: $page->site_id
        );

        return response()->json([
            'message' => 'Recrawl started',
        ]);
    }

    public function import(Request $request, Site $site)
    {

        $request->validate([
            'file' => 'required|file|max:20480', // 20 MB
            'mapping' => 'required|json',
        ]);

        $file = $request->file('file');

        $allowedExtensions = ['csv', 'xls', 'xlsx'];

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions, true)) {

            Log::warning('Invalid import file extension', [
                'site_id' => $site->id,
                'original_name' => $file->getClientOriginalName(),
                'extension' => $extension,
                'mime' => $file->getMimeType(),
            ]);

            throw ValidationException::withMessages([
                'file' => __('Le fichier doit être au format CSV, XLS ou XLSX.'),
            ]);
        }

        Log::info("📥 Import pages started for site {$site->id}", [
            'file' => $file->getClientOriginalName(),
            'extension' => $extension,
            'mime' => $file->getMimeType(),
        ]);

        if ($request->hasFile('file')) {

            $file = $request->file('file');

            $document = $this->saveDocument($file, $site, 'file');

            $mapping = $request->input('mapping') ? json_decode($request->mapping, true, 512, JSON_THROW_ON_ERROR) : [];

            Log::info("📄 File uploaded: {$document->path}");

            // On met le site en processing
            $site->update([
                'status' => 'indexing'
            ]);

            PageImportJob::dispatch($document, $mapping, $site);

            return response()->json([
                'success' => true,
                'document_id' => $document->id,
                'message' => 'Import en cours...',
            ]);
        }


    }

    private function moveImage($file)
    {
        $currentDateTime = Carbon::now();
        $formattedDateTime = $currentDateTime->format('Ymd_His');

        $path_file = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('assets/resources/pages/'), $path_file);

        return "assets/resources/pages/" . $path_file;
    }
    // Méthode pour supprimer une image
    private function deleteImage($path)
    {
        if ( file_exists( public_path($path) ) ) {
            unlink(public_path($path));
        }
    }
    private function saveDocument($files, Site $site, string $type){

        $document = null;
        if (is_array($files)) {

            foreach ($files as $file) {
                $documentPath = $this->moveImage($file);
                $extension = $files->getClientOriginalExtension();
                $document = new Document([ 'id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type, 'extension' => $extension]);
                $document = $site->documents()->save($document);
            }

        } else {

            $documentPath = $this->moveImage($files);
            $extension = $files->getClientOriginalExtension();
            $document = new Document([ 'id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type, 'extension' => $extension]);
            $document = $site->documents()->save($document);

        }

        return $document;
    }
}
