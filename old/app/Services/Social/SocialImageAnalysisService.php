<?php

namespace App\Services\Social;

use App\Services\vision\ImageVisionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialImageAnalysisService
{
    public function __construct(
        private readonly ImageVisionService $imageVisionService,
    ) {}

    /**
     * Cas Facebook/Instagram : l'attachment est une URL publique (CDN Meta),
     * pas besoin d'authentification, mais l'URL expire — téléchargement
     * immédiat obligatoire dans le webhook.
     */
    public function analyzeFromUrl(string $url, ?string $caption, string $logRef): ?array
    {
        try {
            $response = Http::timeout(20)->get($url);

            if (!$response->successful()) {
                Log::warning('[SocialImage] Téléchargement échoué', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return $this->analyzeBytes($response->body(), $caption, $logRef);

        } catch (Throwable $e) {
            Log::warning('[SocialImage] Erreur téléchargement', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Cas Telegram/Gmail/Outlook/IMAP : les octets sont déjà en main (après
     * l'appel spécifique à chaque API pour les récupérer).
     * Même cache global (par hash) que le crawl/documents/produits/widget.
     */
    public function analyzeBytes(string $bytes, ?string $caption, string $logRef): ?array
    {
        return $this->imageVisionService->analyzeBytes(
            $bytes,
            alt: null,
            context: $caption,
            logRef: $logRef,
        );
    }

    /**
     * Fusionne texte d'origine (légende/caption, peut être vide) + résultat
     * vision → alimente `content`, lu par le LLM/RAG en aval. Comportement
     * volontairement identique à ChatController::ask() côté widget web.
     */
    public function buildEnrichedContent(?string $originalText, array $visionResult): string
    {
        $text = trim($originalText ?? '');

        $visionText = trim(implode("\n", array_filter([
            !empty($visionResult['description']) ? "Description de l'image : {$visionResult['description']}" : null,
            !empty($visionResult['ocr_text']) ? "Texte visible sur l'image : {$visionResult['ocr_text']}" : null,
        ])));

        if ($visionText === '') {
            return $text !== '' ? $text : '[Image envoyée, contenu non analysable]';
        }

        return $text !== ''
            ? "{$text}\n\n[Image jointe]\n{$visionText}"
            : "L'utilisateur a envoyé une image sans texte. Voici ce qu'elle contient :\n{$visionText}";
    }

    /**
     * Même forme quel que soit le canal → à fusionner dans metadata['image'].
     */
    public function buildMetadataBlock(array $visionResult, ?string $sourceUrl = null): array
    {
        return [
            'source_url'   => $sourceUrl,
            'content_hash' => $visionResult['content_hash'] ?? null,
            'description'  => $visionResult['description'] ?? null,
            'ocr_text'     => $visionResult['ocr_text'] ?? null,
        ];
    }
}
