<?php

namespace App\Services\vision;

use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

class DocumentImageExtractionService
{
    protected array $rasterExtensions = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];

    /**
     * Point d'entrée unique : extrait toutes les images embarquées d'un document
     * selon son extension. Retourne un tableau de
     * ['bytes' => string, 'filename' => string].
     *
     * Best-effort partout : ne lève jamais d'exception, retourne [] en cas d'échec.
     */
    public function extract(string $fullPath, string $extension): array
    {
        return match (strtolower($extension)) {
            'docx' => $this->extractFromZipMedia($fullPath, 'word/media/'),
            'xlsx' => $this->extractFromZipMedia($fullPath, 'xl/media/'),
            'pdf' => $this->extractFromPdf($fullPath),
            // .doc / .xls (formats binaires OLE pré-2007) : pas de structure ZIP,
            // extraction non supportée pour l'instant.
            default => [],
        };
    }

    /**
     * DOCX et XLSX sont des archives ZIP standard (Office Open XML). Les images
     * embarquées sont des fichiers physiques sous word/media/ ou xl/media/.
     * C'est 100% fiable, aucune dépendance externe requise (ZipArchive est
     * une extension PHP native).
     */
    protected function extractFromZipMedia(string $fullPath, string $mediaPrefix): array
    {
        $images = [];

        if (!class_exists(ZipArchive::class)) {
            Log::warning('Extension PHP ZipArchive absente : extraction images DOCX/XLSX impossible');
            return $images;
        }

        $zip = new ZipArchive();

        if ($zip->open($fullPath) !== true) {
            Log::warning('Impossible d\'ouvrir le document comme archive ZIP', ['path' => $fullPath]);
            return $images;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name === false || !str_starts_with($name, $mediaPrefix)) {
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                // On ignore les formats vectoriels/legacy non exploitables par le
                // vision model (emf, wmf) et les svg (traités comme décoratifs).
                if (!in_array($ext, $this->rasterExtensions, true)) {
                    continue;
                }

                $bytes = $zip->getFromIndex($i);

                if ($bytes === false || $bytes === '') {
                    continue;
                }

                $images[] = [
                    'bytes' => $bytes,
                    'filename' => basename($name),
                ];
            }
        } catch (Throwable $e) {
            Log::warning('Erreur pendant la lecture des images ZIP', [
                'path' => $fullPath,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $zip->close();
        }

        return $images;
    }

    /**
     * Extraction best-effort des images embarquées d'un PDF via Smalot\PdfParser
     * (déjà une dépendance du projet pour l'extraction de texte).
     *
     * Limite connue et assumée : certains encodages d'image PDF (JPXDecode,
     * CCITTFaxDecode...) ne sont pas toujours décodables en un format raster
     * standard par cette librairie. Dans ce cas l'image est silencieusement
     * ignorée (elle échouera au probing dans ImageVisionService, ce qui est
     * sans risque : pas de crash, juste pas d'OCR sur cette image précise).
     * Si besoin d'une couverture à 100% sur des PDF complexes/scannés, une
     * alternative est de rasteriser chaque page via Imagick + Ghostscript,
     * mais ce n'est pas activé par défaut pour ne pas ajouter de dépendance
     * système non confirmée sur ton environnement.
     */
    protected function extractFromPdf(string $fullPath): array
    {
        $images = [];

        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            Log::warning('smalot/pdfparser absent : extraction images PDF impossible');
            return $images;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);

            $imageObjects = $pdf->getObjectsByType('XObject', 'Image');

            $i = 0;

            foreach ($imageObjects as $imageObject) {
                $i++;

                try {
                    $bytes = method_exists($imageObject, 'getContent')
                        ? $imageObject->getContent()
                        : null;

                    if (!$bytes) {
                        continue;
                    }

                    $images[] = [
                        'bytes' => $bytes,
                        'filename' => "pdf-image-{$i}.jpg",
                    ];

                } catch (Throwable $e) {
                    // Encodage non supporté par pdfparser pour cette image précise :
                    // on l'ignore et on continue avec les suivantes.
                    Log::info('Image PDF ignorée (décodage impossible)', [
                        'path' => $fullPath,
                        'index' => $i,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

        } catch (Throwable $e) {
            Log::warning('Extraction images PDF échouée (best-effort, document indexé quand même)', [
                'path' => $fullPath,
                'error' => $e->getMessage(),
            ]);
        }

        return $images;
    }
}
