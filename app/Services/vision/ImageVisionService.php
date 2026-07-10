<?php

namespace App\Services\vision;


use App\Models\Vision\ImageAnalysisCache;
use App\Models\Vision\PageImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImageVisionService
{
    protected string $model;
    protected int $maxBytes;
    protected int $minWidth;
    protected int $minHeight;
    protected int $downloadTimeout;
    protected int $callTimeout;
    protected int $maxRetries;

    public function __construct()
    {
        $this->model = config('vision.model');
        $this->maxBytes = config('vision.max_image_bytes');
        $this->minWidth = config('vision.min_width');
        $this->minHeight = config('vision.min_height');
        $this->downloadTimeout = config('vision.download_timeout');
        $this->callTimeout = config('vision.call_timeout');
        $this->maxRetries = config('vision.max_retries');
    }

    /**
     * Cas 1 : image découverte pendant un CRAWL (App\Models\PageImage), référencée
     * par une URL distante. Utilisé par ProcessImageOcrJob.
     *
     * Retourne null si l'image doit être ignorée (trop petite, illisible,
     * introuvable, décorative...), sinon un tableau
     * ['description', 'ocr_text', 'is_decorative', 'content_hash', 'width', 'height'].
     */
    public function analyzeImageUrl(PageImage $pageImage): ?array
    {
        $bytes = $this->downloadImage($pageImage->url);

        if ($bytes === null) {
            return null;
        }

        return $this->processBytes(
            bytes: $bytes,
            alt: $pageImage->alt,
            context: $pageImage->context,
            logRef: $pageImage->url,
        );
    }

    /**
     * Cas 2 : image indexée DIRECTEMENT par un administrateur (upload de document,
     * pas de crawl) via IndexService::indexDocument(). Le fichier est déjà sur
     * disque, donc pas de téléchargement HTTP : on lit les octets directement.
     *
     * Mêmes règles de filtrage, de cache et le même modèle vision que pour le crawl :
     * une image identique uploadée en document ET rencontrée pendant un crawl
     * (ou l'inverse) ne sera jamais analysée deux fois par le LLM.
     */
    public function analyzeLocalFile(string $absolutePath, ?string $alt = null, ?string $context = null): ?array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            Log::warning('Fichier image introuvable ou illisible', ['path' => $absolutePath]);
            return null;
        }

        $bytes = @file_get_contents($absolutePath);

        if ($bytes === false || $bytes === '') {
            Log::warning('Lecture du fichier image échouée', ['path' => $absolutePath]);
            return null;
        }

        return $this->processBytes(
            bytes: $bytes,
            alt: $alt,
            context: $context,
            logRef: $absolutePath,
        );
    }

    /**
     * Cas 3 : image obtenue en mémoire, sans URL ni fichier sur disque — typiquement
     * une image EXTRAITE d'un conteneur (PDF/DOCX/XLSX) par DocumentImageExtractionService.
     * Mêmes règles de filtrage, même cache global que les 2 autres points d'entrée.
     */
    public function analyzeBytes(string $bytes, ?string $alt = null, ?string $context = null, string $logRef = 'embedded-image'): ?array
    {
        return $this->processBytes(
            bytes: $bytes,
            alt: $alt,
            context: $context,
            logRef: $logRef,
        );
    }

    /**
     * Coeur partagé entre les 3 entrées ci-dessus : probing, filtrage taille,
     * cache global cross-tenant par hash de contenu, appel du modèle vision.
     */
    protected function processBytes(string $bytes, ?string $alt, ?string $context, string $logRef): ?array
    {
        if (strlen($bytes) > $this->maxBytes) {
            Log::info('Image ignorée (trop volumineuse)', [
                'ref' => $logRef,
                'bytes' => strlen($bytes),
            ]);
            return null;
        }

        [$width, $height, $mime] = $this->probeImage($bytes);

        if (!$mime) {
            return null; // pas une image valide décodable
        }

        if ($width && $height && ($width < $this->minWidth || $height < $this->minHeight)) {
            Log::info('Image ignorée (trop petite = probablement décorative)', [
                'ref' => $logRef,
                'width' => $width,
                'height' => $height,
            ]);
            return null;
        }

        $contentHash = hash('sha256', $bytes);

        // 🔥 Cache global cross-tenant ET cross-source (crawl <-> document uploadé) :
        // la même image, quel que soit l'endroit d'où elle vient, n'est JAMAIS
        // ré-analysée par le LLM une fois son hash connu.
        $cached = ImageAnalysisCache::find($contentHash);

        if ($cached) {
            $cached->increment('hits');

            Log::info('Image analysis cache HIT', [
                'content_hash' => $contentHash,
                'ref' => $logRef,
                'hits' => $cached->hits,
            ]);

            if ($cached->is_decorative) {
                return null;
            }

            return [
                'description' => $cached->description,
                'ocr_text' => $cached->ocr_text,
                'is_decorative' => (bool) $cached->is_decorative,
                'content_hash' => $contentHash,
                'width' => $width,
                'height' => $height,
            ];
        }

        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);

        $result = $this->callVisionModel($dataUri, $alt, $context, $logRef);

        ImageAnalysisCache::updateOrCreate(
            ['content_hash' => $contentHash],
            [
                'description' => $result['description'],
                'ocr_text' => $result['ocr_text'],
                'is_decorative' => $result['is_decorative'],
                'model' => $this->model,
            ]
        );

        if ($result['is_decorative'] && $result['ocr_text'] === '' && $result['description'] === '') {
            return null;
        }

        return array_merge($result, [
            'content_hash' => $contentHash,
            'width' => $width,
            'height' => $height,
        ]);
    }

    protected function downloadImage(string $url): ?string
    {
        try {
            $response = Http::timeout($this->downloadTimeout)
                ->connectTimeout(8)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RAGVisionBot/1.0; +https://example.com/bot)',
                    'Accept' => 'image/*',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $contentType = $response->header('Content-Type');

            if ($contentType && !str_starts_with($contentType, 'image/')) {
                return null;
            }

            $body = $response->body();

            return $body !== '' ? $body : null;

        } catch (Throwable $e) {
            Log::warning('Téléchargement image échoué', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?string} [width, height, mime]
     */
    protected function probeImage(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if (!$info) {
            return [null, null, null];
        }

        return [$info[0] ?? null, $info[1] ?? null, $info['mime'] ?? 'image/jpeg'];
    }

    protected function callVisionModel(string $dataUri, ?string $alt, ?string $context, string $logRef): array
    {
        $systemPrompt = <<<EOT
Tu es un système de vision expert intégré à un moteur RAG (Retrieval-Augmented Generation) pour un chatbot d'entreprise.

Ta mission : analyser une image (extraite d'une page web, ou uploadée directement par un administrateur) et en tirer tout ce qui est utile pour répondre plus tard à des questions d'utilisateurs.

Retourne STRICTEMENT un JSON valide, sans aucun texte en dehors, avec ce format :

{
  "description": "string",
  "ocr_text": "string",
  "is_decorative": boolean
}

RÈGLES :
- "description" : ce que montre l'image, de façon factuelle et utile (schéma, capture d'écran de logiciel, infographie, photo produit, graphique, tableau, diagramme, document scanné...). Reste concis mais précis.
- "ocr_text" : la transcription EXACTE et COMPLÈTE de tout texte lisible dans l'image (titres, légendes, valeurs de tableau, texte de capture d'écran, texte d'un document scanné...). Si aucun texte visible, retourne une chaîne vide "".
- "is_decorative" : true si l'image est purement décorative ou sans valeur informative (logo, icône, bouton, séparateur visuel, photo générique sans texte ni donnée) auquel cas description et ocr_text peuvent être vides ou très courts.
- N'invente JAMAIS de contenu absent de l'image.
- Réponds en français, sauf pour "ocr_text" où tu dois retranscrire le texte dans sa langue d'origine.
EOT;

        $contextParts = array_filter([
            $alt ? "Texte alternatif / nom du fichier : {$alt}" : null,
            $context ? "Contexte associé à l'image : {$context}" : null,
        ]);

        $userText = "Analyse cette image."
            . (!empty($contextParts) ? "\n\n" . implode("\n", $contextParts) : '');

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
            ],
        ];

        $delay = 1;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                Log::info("Vision call attempt {$attempt}", [
                    'model' => $this->model,
                    'ref' => $logRef,
                ]);

                $response = Http::timeout($this->callTimeout)
                    ->connectTimeout(10)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . config('vision.openrouter_api_key'),
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'RAG SaaS Engine - Vision',
                    ])
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => $this->model,
                        'messages' => $messages,
                        'temperature' => 0.1,
                        'max_tokens' => 1000,
                    ]);

                if (!$response->successful()) {
                    Log::warning('Vision HTTP error', [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 500),
                    ]);

                    if ($attempt < $this->maxRetries) {
                        sleep($delay);
                        $delay *= 2;
                        continue;
                    }
                    break;
                }

                $data = $response->json();
                $content = trim($data['choices'][0]['message']['content'] ?? '');

                if ($content === '') {
                    Log::warning('Vision empty response', ['attempt' => $attempt]);
                    if ($attempt < $this->maxRetries) {
                        sleep($delay);
                        $delay *= 2;
                        continue;
                    }
                    break;
                }

                $content = preg_replace('/^```json|```$/i', '', $content);
                $content = trim($content);

                $parsed = json_decode($content, true);

                if (!is_array($parsed)) {
                    Log::warning('Vision invalid JSON', [
                        'attempt' => $attempt,
                        'raw' => substr($content, 0, 500),
                    ]);
                    if ($attempt < $this->maxRetries) {
                        sleep($delay);
                        $delay *= 2;
                        continue;
                    }
                    break;
                }

                Log::info('Vision success', ['attempt' => $attempt, 'ref' => $logRef]);

                return [
                    'description' => trim((string) ($parsed['description'] ?? '')),
                    'ocr_text' => trim((string) ($parsed['ocr_text'] ?? '')),
                    'is_decorative' => (bool) ($parsed['is_decorative'] ?? false),
                ];

            } catch (Throwable $e) {
                Log::warning('Vision call exception', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $this->maxRetries) {
                sleep($delay);
                $delay *= 2;
            }
        }

        Log::error('Vision failed after max retries', [
            'ref' => $logRef,
        ]);

        // 🔥 fallback safe : ne jamais casser le pipeline d'indexation
        return [
            'description' => '',
            'ocr_text' => '',
            'is_decorative' => true,
        ];
    }
}
