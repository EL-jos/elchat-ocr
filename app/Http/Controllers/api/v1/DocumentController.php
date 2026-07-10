<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Jobs\document\IndexDocumentJob;
use App\Jobs\product\ProductImportJob;
use App\Models\Document;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * Upload et créer un document pour un site
     */
    public function store(Request $request, Site $site)
    {

        //dd($request->all(), $site);
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp,gif,bmp,tiff,tif',
            'mapping' => 'nullable|json',
        ]);

        if ($request->hasFile('file')) {
            $files = $request->file('file');
            $document = $this->saveDocument($files, $site, 'file');
            $mapping = $request->input('mapping') ? json_decode($request->mapping, true, 512, JSON_THROW_ON_ERROR) : [];


            Log::info("Document uploadé: {$document->path}");

            $site->update(['status' => 'indexing']); // site en cours d'indexation

            if (!empty($mapping) && $mapping !== []){
                // Dispatch indexation
                ProductImportJob::dispatch($document, $mapping, $site);
            }else{
                // Dispatch indexation
                IndexDocumentJob::dispatch($document ,$site); // documents standards
            }

            return response()->json([
                'success' => true,
                'document_id' => $document->id,
                'message' => 'Document uploadé et indexation en cours',
            ]);
        }

    }
    private function moveImage($file)
    {
        $currentDateTime = Carbon::now();
        $formattedDateTime = $currentDateTime->format('Ymd_His');

        $path_file = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('assets/resources/documents/'), $path_file);

        return "assets/resources/documents/" . $path_file;
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
