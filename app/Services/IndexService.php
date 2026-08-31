<?php
namespace App\Services;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\FieldSynonym;
use App\Models\Page;
use App\Models\Product;
use App\Models\Site;
use App\Models\Vision\PageImage;
use App\Services\ia\EmbeddingService;
use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorIndexService;
use App\Services\vision\DocumentImageExtractionService;
use App\Services\vision\ImageVisionService;
use App\Traits\TextNormalizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexService
{

    use TextNormalizer;
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected VectorIndexService $vectorIndexService,
        protected LexicalIndexService  $lexicalIndexService,
        protected DocumentCanonicalService $documentCanonicalService,
        protected DocumentChunkingService $documentChunkingService,
        protected MercureService $mercureService,
        protected ImageVisionService $imageVisionService,
        protected DocumentImageExtractionService $documentImageExtractionService,
        protected DocumentPathResolver $documentPathResolver,
    ) {}
    public function indexPage(Page $page, array $context = []): void
    {
        Log::info("DANS INDEX PAGES");

        // 🛑 Idempotence  : ne jamais réindexer
        if ($page->is_indexed) {
            Log::info("DANS INDEX PAGES deja indexée ", [
                'page_id' => $page->id,
                'page_title' => $page->title,
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            $chunks = $this->buildChunks($page);

            /*Log::info("CHUNKS", [
                'chunk' => $chunks,
            ]);*/

            $this->lexicalIndexService->ensureIndex($page->site_id);

            foreach ($chunks as $i => $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];

                /*Log::info("DANS INDEX PAGES contenu du chunk: ", [
                    'text chunk' => $textChunk,
                ]);*/


                if ($this->chunkAlreadyExists($page, $textChunk)) continue;
                if (trim($textChunk) === '') continue;
                //$embedding = $this->embeddingService->getEmbedding($textChunk);
                if (!($chunkData['no_embedding'] ?? false)) {
                    $embedding = $this->embeddingService->getEmbedding($textChunk);
                } else {
                    $embedding = null;
                }

                $hash = hash('sha256', $textChunk . ($page->id ?? ''));

                $chunk = Chunk::create([
                    'page_id'     => $page->id,
                    'site_id'     => $page->site_id,
                    'source_type' => $context['source'] ?? $page->source ?? 'unknown',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'document_id' => null,
                    'hash'        => $hash,
                ]);

                $this->lexicalIndexService->upsertChunk([
                    'id' => (string) $chunk->id,
                    'text' => $chunk->text,
                    'content' => $chunk->text, // 🔥 duplication stratégique
                    'title' => $page->title ?? null,
                    'url' => $page->url ?? null,
                    'site_id' => $chunk->site_id,
                    'source_type' => $chunk->source_type,
                    'priority' => $chunk->priority,
                ], $chunk->site_id);

                if ($embedding) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $page->site->id,
                        chunkId: $chunk->id,
                        embedding: $embedding,
                        payload: [
                            'site_id'  => $chunk->site_id,
                            'page_id'  => $chunk->page_id,
                            'priority' => $priority,
                            'source'  => $page->source,
                            'title'   => $page->title,
                            'url'     => $page->url,
                        ],
                        collection: "chunks_{$chunk->site_id}"
                    );
                }

            }

            // ✅ Page marquée indexée SEULEMENT si tout est OK
            $page->update(['is_indexed' => true]);

            DB::commit();

            Log::info('Page indexée', [
                'site_id' => $page->site_id,
                'page_id' => $page->id,
                'chunks' => count($chunks),
            ]);


        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Indexation échouée', [
                'page_id' => $page->id,
                'url' => $page->url,
                'error' => $e->getMessage(),
            ]);

            throw $e; // laisse le job gérer le retry
        }
    }
    /**
     * Indexe le résultat d'analyse vision d'une image dans le pipeline RAG.
     *
     * Appelé de façon ASYNCHRONE par ProcessImageOcrJob, une fois qu'une
     * image a été téléchargée, analysée par le vision model, et que sa
     * description + son OCR sont disponibles sur le PageImage.
     *
     * Le chunk produit fusionne texte (description + OCR) pour le retrieval
     * sémantique/lexical ET conserve `image_url` en metadata sur le payload
     * vecteur + le document lexical, pour que le RAG puisse citer / afficher
     * l'image dans la réponse du chatbot.
     *
     * Idempotent : peut être rappelé sans dupliquer (hash de contenu + chunk_id).
     */
    public function indexImageChunk(PageImage $pageImage): void
    {
        if ($pageImage->status !== 'done') {
            return;
        }

        // 🛑 Idempotence : image déjà indexée
        if ($pageImage->chunk_id) {
            Log::info('Image déjà indexée, skip', ['page_image_id' => $pageImage->id]);
            return;
        }

        $page = $pageImage->page;
        if (!$page) {
            Log::warning('indexImageChunk: page introuvable', ['page_image_id' => $pageImage->id]);
            return;
        }

        $descriptionText = trim((string) $pageImage->description);
        $ocrText = trim((string) $pageImage->ocr_text);

        // Image sans valeur informative (décorative) -> pas de chunk, pas de coût embedding
        if ($descriptionText === '' && $ocrText === '') {
            return;
        }

        $parts = array_filter([
            $page->title ? "Page: {$page->title}" : null,
            "URL: {$page->url}",
            $descriptionText !== '' ? "Description de l'image: {$descriptionText}" : null,
            $ocrText !== '' ? "Texte extrait de l'image (OCR): {$ocrText}" : null,
        ]);

        $fullText = implode("\n", $parts);

        $hash = hash('sha256', $fullText . $pageImage->id);

        if (Chunk::where('page_id', $page->id)->where('hash', $hash)->exists()) {
            return;
        }

        DB::beginTransaction();

        try {
            $embedding = $this->embeddingService->getEmbedding($fullText);

            // 🖼️ Metadata standardisée, IDENTIQUE à celle utilisée par les images
            // "document direct" et "embarquées" (indexImageDocument /
            // indexEmbeddedDocumentImages) : c'est ce que lisent ContextBuilder,
            // EntityResolver et ImageEntityTransformer en aval pour renvoyer
            // l'image au client.
            $metadata = [
                'is_image'  => true,
                'image_url' => $pageImage->url,
                'title'     => $page->title ?? null,
                'url'       => $page->url ?? null,
                'alt'       => $pageImage->alt ?? null,
                'page_id'   => $page->id,
            ];

            $chunk = Chunk::create([
                'page_id'     => $page->id,
                'site_id'     => $page->site_id,
                'source_type' => 'image',
                'text'        => $fullText,
                'priority'    => $this->computeSemanticPriority($fullText, 'image'),
                'document_id' => null,
                'metadata'    => $metadata,
                'hash'        => $hash,
            ]);

            $this->lexicalIndexService->ensureIndex($page->site_id);

            $this->lexicalIndexService->upsertChunk([
                'id'          => (string) $chunk->id,
                'text'        => $chunk->text,
                'content'     => $chunk->text,
                'title'       => $page->title ?? null,
                'url'         => $page->url ?? null,
                'site_id'     => $chunk->site_id,
                'source_type' => 'image',
                'priority'    => $chunk->priority,
                'metadata'    => $metadata,
                'image_url'   => $pageImage->url,
            ], $chunk->site_id);

            if ($embedding) {
                $this->vectorIndexService->upsertChunk(
                    siteId: $page->site_id,
                    chunkId: $chunk->id,
                    embedding: $embedding,
                    payload: [
                        'site_id'   => $chunk->site_id,
                        'page_id'   => $chunk->page_id,
                        'priority'  => $chunk->priority,
                        'source'    => 'image',
                        'title'     => $page->title,
                        'url'       => $page->url,
                        'metadata'  => $metadata,
                        'image_url' => $pageImage->url,
                        'type'      => 'image',
                    ],
                    collection: "chunks_{$chunk->site_id}"
                );
            }

            $pageImage->update(['chunk_id' => $chunk->id]);

            DB::commit();

            Log::info('Image chunk indexée', [
                'site_id' => $page->site_id,
                'page_id' => $page->id,
                'image_url' => $pageImage->url,
                'chunk_id' => $chunk->id,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Indexation image échouée', [
                'page_image_id' => $pageImage->id,
                'url' => $pageImage->url,
                'error' => $e->getMessage(),
            ]);

            throw $e; // laisse le job gérer le retry
        }
    }
    protected function buildChunks(Page $page): array
    {
        // Cas 1 : contenu structuré (JSON depuis CrawlService B)
        $decoded = json_decode($page->content, true);

        /*Log::info("DANS BUILD CHUNKS ", [
            'decoded' => $decoded,
            'page_id' => $page->id,
            'page_title' => $page->title,
        ]);*/

        if (is_array($decoded)) {
            //Log::info("EST UN TABLEAU  ");
            if (isset($decoded['sections'])) {
                $decoded = $decoded['sections'];
            }
            return $this->buildChunksFromSections($page, $decoded);
        }
        //Log::info("EST UN TEXTE NORAL ");
        // Cas 2 : contenu brut (fallback robuste)
        return $this->buildChunksFromRawText($page);
    }
    /*protected function buildChunksFromSections(Page $page, array $sections): array
    {
        $chunks = [];

        foreach ($sections as $sectionIndex => $section) {
            //$sectionTitle = trim($section['title'] ?? '');
            $sectionTitle = trim(
                $section['title']
                ?? $section['h2']
                ?? ''
            );
            $content      = trim($section['content'] ?? '');

            //if (mb_strlen($content) < 100) continue; //Elle empêche l’indexation des petites sections.

            $contextHeader = implode("\n", array_filter([
                $page->title ? "Page: {$page->title}" : null,
                $section['h1'] ?? null, // 🔥 AJOUT CRITIQUE
                $sectionTitle ? "Section: {$sectionTitle}" : null,
                "URL: {$page->url}",
            ]));

            $fullText = $contextHeader . "\n\n" . $content;

            foreach ($this->chunkBySentences($fullText, 800, 120) as $chunkText) {
                $weight = $section['weight'] ?? 1;

                //$base = $this->computePriority($sectionIndex, $sectionTitle);

                $semantic = $this->computeSemanticPriority($chunkText, 'section');

                $priority = (int) round($semantic * (0.7 + 0.3 * $weight));

                $chunks[] = [
                    'text' => $chunkText,
                    'priority' => $priority,
                    // 🔥 IMPORTANT
                    'no_embedding' => $section['no_embedding'] ?? false,
                    'type' => $sectionTitle === 'Overview' ? 'overview' : 'section',
                ];
            }
        }

        return $chunks;
    }*/
    protected function buildChunksFromSections(Page $page, array $sections): array
    {
        //Log::info("DANS BUILD CHUNKS FRO SECTIONS ");
        $chunks = [];

        $buffer = '';
        $bufferMeta = [
            'h1' => null,
            'h2' => null,
            'h3' => null,
            'weight' => 1, // 🔥 AJOUT
        ];

        foreach ($sections as $i => $section) {

            /*Log::info("SECTION CONCERNEE ", [
                'section' => $section,
                'content' => trim($section['content'] ?? '')
            ]);*/

            $content = trim($section['content'] ?? '');
            if ($content === '') continue;

            $h1 = trim($section['h1'] ?? '');
            $h2 = trim($section['h2'] ?? '');
            $h3 = trim($section['h3'] ?? '');

            // 🔥 Update contexte seulement si pertinent
            if ($h1) $bufferMeta['h1'] = $h1;
            if ($h2) $bufferMeta['h2'] = $h2;
            if ($h3) $bufferMeta['h3'] = $h3;

            if (isset($section['weight'])) {
                $bufferMeta['weight'] = max(
                    $bufferMeta['weight'] ?? 1,
                    $section['weight']
                );
            }

            // 🔥 Ajout au buffer
            $buffer .= "\n" . $content;

            $buffer = trim($buffer);

            /*Log::info("BUFFER AVANT SHOULD FLUSH BUFFER ", [
                'buffer' => $buffer,
            ]);*/

            // 🔥 flush intelligent
            if ($this->shouldFlushBuffer($buffer, $content, $i, $sections)) {

                $chunks = array_merge(
                    $chunks,
                    $this->createSmartChunks($page, $buffer, $bufferMeta)
                );

                /*Log::info("flush intelligent ", [
                    'chunks' => $chunks,
                ]);*/

                $buffer = '';
                $bufferMeta = [
                    'h1' => null,
                    'h2' => null,
                    'h3' => null,
                    'weight' => 1, // 🔥 important
                ];
            }
        }

        // flush final
        if (trim($buffer) !== '') {
            /*Log::info("BUFFER ", [
                'buffer' => $buffer,
            ]);*/
            $chunks = array_merge(
                $chunks,
                $this->createSmartChunks($page, $buffer, $bufferMeta)
            );
        }

        /*Log::info("SECTION CONCERNEE SORTIE ", [
            'buffer' => $buffer,
            'chunks' => $chunks
        ]);*/

        // 🔥 DEDUP GLOBAL FINAL (CRUCIAL)
        return $this->deduplicateChunks($chunks, $page);
    }
    protected function createSmartChunks(Page $page, string $buffer, array $meta): array
    {

        //Log::info("DANS CREATE SART CHUNKS ");
        $header = implode("\n", array_filter([
            $page->title ? "Page: {$page->title}" : null,
            $meta['h1'] ?? null,
            $meta['h2'] ?? null,
            $meta['h3'] ?? null,
            "URL: {$page->url}",
        ]));

        //$text = $header . "\n\n" . $buffer;
        $context = implode("\n", array_filter([
            $meta['h1'] ?? null,
            $meta['h2'] ?? null,
            $meta['h3'] ?? null,
        ]));

        $text = implode("\n\n", array_filter([
            $page->title ? "Page: {$page->title}" : null,
            $context ? "Context: {$context}" : null,
            "Content:\n{$buffer}",
        ]));

        $chunks = [];

        foreach ($this->chunkBySentences($text, 800, 1) as $chunkText) {

            $semantic = $this->computeSemanticPriority($chunkText, 'section', $meta['h2'] ?? null);
            $weight = $meta['weight'] ?? 1;

            $priority = (int) round($semantic * (0.7 + 0.3 * $weight));

            $chunks[] = [
                'text' => $chunkText,
                'priority' => $priority,
                // 🔥 IMPORTANT
                'no_embedding' => $meta['no_embedding'] ?? false,
                'type' => ($meta['h2'] ?? '') === 'Overview' ? 'overview' : 'section',
            ];

            /*Log::info("CHUNKS COUURE ", [
                'chunks' => $chunks,
            ]);*/

        }

        return $chunks;
    }
    protected function shouldFlushBuffer(string $buffer, string $current, int $i, array $sections): bool
    {
        $total = count($sections);

        /*Log::info("DANS SHOULD FLUSH BUFFER ", [
            'buffer' => $buffer,
            'current' => $current,
            'i' => $i,
            'sections' => $sections,
            'total' => $total,
        ]);*/

        // 🔥 PRIORITÉ MAX : changement de bloc logique
        if ($i < $total - 1) {

            $next = $sections[$i + 1];
            $currentH3 = $sections[$i]['h3'] ?? null;
            $nextH3 = $next['h3'] ?? null;
            // 🔴 CAS 1 : les deux ont h3 → flush si différent
            if ($currentH3 !== null && $nextH3 !== null && $currentH3 !== $nextH3) {
                //Log::info("CAS 1 : les deux ont h3 → flush si différent");
                return true;
            }
            // 🔴 CAS 2 : un a h3 et l'autre non → flush aussi
            if (($currentH3 === null && $nextH3 !== null) || ($currentH3 !== null && $nextH3 === null)) {
                //Log::info("CAS 2 : un a h3 et l'autre non → flush aussi");
                return true;
            }

        }

        // trop petit → continue
        if (mb_strlen($buffer) < 500) {
            //Log::info("trop petit → continue");
            return false;
        }

        // trop grand → flush
        if (mb_strlen($buffer) > 1200) {
            //Log::info("trop grand → flush");
            return true;
        }

        // changement de logique (h2 change)
        if ($i < $total - 1) {
            $next = $sections[$i + 1] ?? null;

            if ($next && ($next['h2'] ?? null) !== ($sections[$i]['h2'] ?? null)) {
                //Log::info("changement de logique (h2 change)");
                return true;
            }
        }

        //Log::info("RIEN DE TOUT CA ");

        return false;
    }
    protected function deduplicateChunks(array $chunks, Page $page): array
    {
        /*Log::info("DANS DEDULICATECHUNKS ", [
            'chunks' => $chunks,
            'page_id' => $page->id,
            'age_title' => $page->title,
        ]);*/
        $seen = [];
        $final = [];

        foreach ($chunks as $chunk) {

            $fingerprint = hash('sha256',
                $page->id . '|' .
                preg_replace('/\s+/', ' ', strtolower($chunk['text']))
            );

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;

            $final[] = $chunk;
        }

        /*Log::info("SORTIE DE  DEDULICATECHUNKS ", [
            'seen' => $seen,
            'final' => $final,
        ]);*/

        return $final;
    }
    protected function buildChunksFromRawText(Page $page): array
    {
        $text = trim($page->content);
        //if (mb_strlen($text) < 300) return []; //Sinon les petites pages ne sont jamais indexées.

        $header = implode("\n", array_filter([
            $page->title ? "Page: {$page->title}" : null,
            "URL: {$page->url}",
        ]));

        $chunks = [];
        foreach ($this->chunkBySentences($header . "\n\n" . $text, 800, 1) as $chunkText) {
            $chunks[] = [
                'text' => $chunkText,
                'priority' => 50, // fallback priority neutre
            ];
        }

        return $chunks;
    }
    /*protected function chunkBySentences(string $text, int $maxChars, int $overlapSentences = 1): array
    {
        if (substr_count($text, '.') < 2 && mb_strlen($text) > 300) {
            return $this->chunkText($text, 120, 0.2);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text);

        if (!$sentences || count($sentences) === 0) {
            return [trim($text)];
        }

        $chunks = [];
        $buffer = [];

        foreach ($sentences as $sentence) {

            // 🔥 phrase trop longue
            if (mb_strlen($sentence) > $maxChars) {
                if (!empty($buffer)) {
                    $chunks[] = implode(' ', $buffer);
                    $buffer = [];
                }

                $chunks = array_merge($chunks, $this->chunkText($sentence, 120, 0.2));
                continue;
            }

            $testBuffer = implode(' ', [...$buffer, $sentence]);

            if (mb_strlen($testBuffer) <= $maxChars) {
                $buffer[] = $sentence;
            } else {
                if (!empty($buffer)) {
                    $chunks[] = implode(' ', $buffer);
                }

                $buffer = array_slice($buffer, -$overlapSentences);
                $buffer[] = $sentence;
            }
        }

        if (!empty($buffer)) {
            $chunks[] = implode(' ', $buffer);
        }

        return $chunks;
    }*/
    protected function chunkBySentences(string $text, int $maxChars, int $overlapSentences = 1): array
    {
        /*Log::info('CHUNK_START', [
            'text_length' => mb_strlen($text),
            'maxChars' => $maxChars,
            'overlapSentences' => $overlapSentences,
        ]);*/

        // 🔥 fallback si peu de phrases
        if (substr_count($text, '.') < 2 && mb_strlen($text) > 300) {
            /*Log::info('CHUNK_FALLBACK_TRIGGERED', [
                'reason' => 'not_enough_sentences',
                'dot_count' => substr_count($text, '.'),
            ]);*/

            return $this->chunkText($text, 120, 0.2);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences);

        /*Log::info('CHUNK_SENTENCES_SPLIT', [
            'count' => count($sentences),
            'sentences_preview' => array_slice($sentences, 0, 5),
        ]);*/

        if (!$sentences || count($sentences) === 0) {
            /*Log::warning('CHUNK_EMPTY_SENTENCES', [
                'text_preview' => mb_substr($text, 0, 200),
            ]);*/
            return [trim($text)];
        }

        $chunks = [];
        $buffer = [];

        foreach ($sentences as $index => $sentence) {

            /*Log::debug('CHUNK_SENTENCE_PROCESSING', [
                'index' => $index,
                'sentence_length' => mb_strlen($sentence),
                'sentence_preview' => mb_substr($sentence, 0, 100),
            ]);*/

            // 🔥 phrase trop longue
            if (mb_strlen($sentence) > $maxChars) {

                /*Log::warning('CHUNK_LONG_SENTENCE', [
                    'index' => $index,
                    'length' => mb_strlen($sentence),
                ]);*/

                if (!empty($buffer)) {
                    $chunkText = implode(' ', $buffer);

                    /*Log::info('CHUNK_FLUSH_BEFORE_LONG_SENTENCE', [
                        'chunk_length' => mb_strlen($chunkText),
                        'chunk_preview' => mb_substr($chunkText, 0, 150),
                    ]);*/

                    $chunks[] = $chunkText;
                    $buffer = [];
                }

                $splitChunks = $this->chunkText($sentence, 120, 0.2);

                /*Log::info('CHUNK_LONG_SENTENCE_SPLIT', [
                    'resulting_chunks' => count($splitChunks),
                ]);*/

                $chunks = array_merge($chunks, $splitChunks);
                continue;
            }

            $testBuffer = implode(' ', [...$buffer, $sentence]);

            if (mb_strlen($testBuffer) <= $maxChars) {

                /*Log::debug('CHUNK_BUFFER_APPEND', [
                    'new_length' => mb_strlen($testBuffer),
                ]);*/

                $buffer[] = $sentence;

            } else {

                /*Log::info('CHUNK_FLUSH_MAX_REACHED', [
                    'buffer_length' => mb_strlen(implode(' ', $buffer)),
                    'next_sentence_length' => mb_strlen($sentence),
                ]);*/

                if (!empty($buffer)) {
                    $chunkText = implode(' ', $buffer);

                    /*Log::info('CHUNK_CREATED', [
                        'chunk_length' => mb_strlen($chunkText),
                        'chunk_preview' => mb_substr($chunkText, 0, 150),
                    ]);*/

                    $chunks[] = $chunkText;
                }

                // 🔁 overlap
                $overlap = array_slice($buffer, -$overlapSentences);

                /*Log::debug('CHUNK_OVERLAP_APPLIED', [
                    'overlap_count' => count($overlap),
                    'overlap_preview' => implode(' ', $overlap),
                ]);*/

                $buffer = $overlap;
                $buffer[] = $sentence;
            }
        }

        // 🔚 flush final
        if (!empty($buffer)) {
            $chunkText = implode(' ', $buffer);

            /*Log::info('CHUNK_FINAL_FLUSH', [
                'chunk_length' => mb_strlen($chunkText),
                'chunk_preview' => mb_substr($chunkText, 0, 150),
            ]);*/

            $chunks[] = $chunkText;
        }

        /*Log::info('CHUNK_END', [
            'total_chunks' => count($chunks),
        ]);*/

        return $chunks;
    }
    protected function computePriority(int $sectionIndex, ?string $title): int
    {
        /*Log::info("SectionIndex dans computePriority", [
            "sectionIndex" => $sectionIndex,
            "title"       => $title,
        ]);*/
        $score = 50; // valeur neutre par défaut

        if ($sectionIndex === 0) $score += 20;
        if ($title) $score += 10;
        if (preg_match('/faq|question|help|guide/i', $title ?? '')) {
            $score += 20;
        }

        return $score;
    }
    protected function chunkAlreadyExists(Page $page, string $text): bool
    {
        //$hash = sha1($text);
        $hash = hash('sha256', $text . ($page->id ?? ''));

        return Chunk::where('site_id', $page->site_id)
            ->where('page_id', $page->id)
            ->where('hash', $hash)
            ->exists();
    }
    /**
     * Découpe avec overlap
     */
    protected function chunkText( string $text, int $chunkSize, float $overlapRatio ): array {
        $words = preg_split('/\s+/', trim($text));
        $words = array_values(array_filter($words));
        $chunks = [];

        $overlap = (int) round($chunkSize * $overlapRatio);
        $step = max(1, $chunkSize - $overlap);

        for ($i = 0; $i < count($words); $i += $step) {
            $chunkWords = array_slice($words, $i, $chunkSize);
            if ($chunkWords) {
                $chunks[] = implode(' ', $chunkWords);
            }
        }

        return $chunks;
    }
    /**
     * Indexe un document (PDF, Word, TXT)
     */
    public function indexDocument(Site $site, Document $document, array $context = []): void
    {
        $siteId = $document->documentable_id ?? null;

        // 👁️ Cas "image indexée directement par l'admin" (upload, pas de crawl) :
        // le pipeline texte (documentCanonicalService + documentChunkingService)
        // ne sait pas lire une image -> on bascule sur le pipeline vision/OCR,
        // qui produit un chunk RAG à partir de la description + l'OCR du modèle.
        if ($this->isImageExtension($document->extension)) {
            $this->indexImageDocument($site, $document);
            return;
        }

        /*Log::info("DANS INDEX DOCUMENT", [
            "site_id" => $siteId,
            "context" => $context,
            "document" => $document,
        ]);*/

        $this->publishProgress(
            $siteId,
            "Analyse du document…",
            5
        );

        // 1️⃣ Canonical + Chunking (PURE CPU, PAS DB)
        $path = $this->documentPathResolver->resolve($document);
        if (!$path || !is_file($path)) {
            throw new \RuntimeException('Fichier documentaire introuvable pour l’indexation.');
        }
        /*Log::info("PATH OF FILE", [
            "path" => $path
        ]);*/
        $canonical = $this->documentCanonicalService->buildCanonicalDocument(
            path: $path,
            extension: $document->extension,
            fullPath: $path,
            title: $document->title,
        );

        //Log::info("CANONICAL", $canonical);

        $this->publishProgress(
            $siteId,
            "Construction des chunks intelligents…",
            15
        );


        $chunks = $this->documentChunkingService->chunk($canonical);

        $totalChunks = count($chunks);

        if ($totalChunks === 0) {

            $this->publishProgress(
                $siteId,
                "Aucun contenu indexable trouvé",
                100,
                true,
                'indexing_warning'
            );

            return;
        }

        $this->publishProgress(
            $siteId,
            "Préparation des index de recherche…",
            20
        );

        $this->lexicalIndexService->ensureIndex($siteId);

        $indexedChunks = [];
        $processed = 0;

        // 2️⃣ Embeddings AVANT DB transaction
        foreach ($chunks as $chunkData) {

            $processed++;

            $this->publishProgress(
                $siteId,
                "Analyse sémantique des chunks ({$processed}/{$totalChunks})…",
                min(70, 20 + intval(($processed / max($totalChunks, 1)) * 50)),
                false,
                'indexing_progress',
            );

            $textChunk = trim($chunkData['text'] ?? '');

            if ($textChunk === '') {
                continue;
            }

            try {
                $embedding = !($chunkData['no_embedding'] ?? false)
                    ? $this->embeddingService->getEmbedding($textChunk)
                    : null;
            } catch (\Throwable $e) {
                Log::warning("Embedding failed", [
                    'document_id' => $document->id,
                    'preview' => mb_substr($textChunk, 0, 100),
                    'error' => $e->getMessage(),
                ]);

                $this->publishProgress(
                    $siteId,
                    "Erreur embedding sur un chunk",
                    min(95, 20 + intval(($processed / max($totalChunks, 1)) * 70)),
                    false,
                    'indexing_warning'
                );

                continue;
            }

            $indexedChunks[] = [
                'text' => $textChunk,
                'priority' => $chunkData['priority'],
                'metadata' => array_merge(
                    $chunkData['metadata'] ?? [],
                    array_filter([
                        'source_system' => $document->origin === 'microsoft_365' ? 'microsoft_365' : null,
                        'provider_tenant_id' => $document->access_tenant_id,
                        'provider_principal_id' => $document->access_principal_id,
                        'external_id' => $document->external_id,
                        'external_drive_id' => $document->external_drive_id,
                        'external_site_id' => $document->external_site_id,
                        'external_web_url' => $document->external_web_url,
                    ], static fn ($value) => $value !== null),
                ),
                'embedding' => $embedding,
                'hash' => hash('sha256', $textChunk),
                'lexical_text' => $chunkData['lexical_text'] ?? $this->normalizeForLexicalSearch($textChunk),
            ];
        }

        // 3️⃣ DB TRANSACTION ONLY (FAST)
        DB::beginTransaction();

        try {

            $stored = 0;
            $totalIndexed = count($indexedChunks);

            $existingHashes = Chunk::query()
                ->where('document_id', $document->id)
                ->pluck('hash')
                ->flip()
                ->toArray();

            foreach ($indexedChunks as $data) {

                $stored++;
                $this->publishProgress(
                    $siteId,
                    "Indexation des chunks ({$stored}/{$totalIndexed})…",
                    min(95, 70 + intval(($stored / max($totalIndexed, 1)) * 25)),
                    false,
                    'indexing_progress',
                );

                if (isset($existingHashes[$data['hash']])) {
                    continue;
                }

                $chunk = Chunk::create([
                    'page_id'     => null,
                    'site_id'     => $siteId,
                    'document_id' => $document->id,
                    'source_type' => 'document',
                    'text'        => $data['text'],
                    'priority'    => $data['priority'],
                    'metadata'    => $data['metadata'],
                    'hash'        => $data['hash'],
                ]);
                $existingHashes[$data['hash']] = true;

                $this->lexicalIndexService->upsertChunk([
                    'id' => (string) $chunk->id,
                    'site_id' => $siteId,
                    'document_id' => $document->id,
                    'source_type' => 'document',
                    'text' => $data['lexical_text'],
                    'content' => $chunk->text,
                    'priority' => $chunk->priority,
                    'metadata' => $chunk->metadata,
                ], $siteId);

                if ($data['embedding']) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $siteId,
                        chunkId: $chunk->id,
                        embedding: $data['embedding'],
                        payload: [
                            'site_id' => $siteId,
                            'document_id' => $document->id,
                            'source_type' => 'document',
                            'priority' => $chunk->priority,
                            'metadata' => $chunk->metadata,
                        ],
                        collection: "chunks_{$siteId}"
                    );
                }
            }

            DB::commit();

            Log::info("Document indexé", [
                'document_id' => $document->id,
                'chunks_count' => count($indexedChunks),
            ]);

            // 👁️ Images embarquées (PDF/DOCX/XLSX) : indexées EN PLUS du texte
            // ci-dessus, jamais à sa place. Erreurs absorbées : un souci sur les
            // images ne doit jamais faire échouer l'indexation du texte déjà commit.
            try {
                $this->indexEmbeddedDocumentImages($site, $document);
            } catch (\Throwable $e) {
                Log::warning('Indexation des images embarquées échouée (document texte indexé quand même)', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->publishProgress(
                $siteId,
                "Document indexé avec succès",
                100,
                true,
                'indexing_progress',
            );

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error("Indexation échouée", [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $this->publishProgress(
                $siteId,
                "Erreur pendant l’indexation",
                100,
                true,
                'indexing_error',
                [
                    'error' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }
    /**
     * true si l'extension correspond à une image (jpg, png, webp...), auquel cas
     * indexDocument() bascule sur le pipeline vision/OCR au lieu du pipeline texte.
     */
    protected function isImageExtension(?string $extension): bool
    {
        return in_array(
            strtolower((string) $extension),
            config('vision.image_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif']),
            true
        );
    }

    /**
     * Indexe une image uploadée DIRECTEMENT par un administrateur comme Document
     * (pas de crawl, pas de PageImage). Réutilise le même ImageVisionService et
     * donc le MÊME cache global cross-tenant par hash de contenu que le pipeline
     * de crawl : si cette image (mêmes octets) a déjà été vue ailleurs (un crawl,
     * un autre tenant), aucun appel au modèle vision n'est refait.
     *
     * Traitement volontairement SYNCHRONE (contrairement au crawl) : il s'agit
     * d'un upload unique et explicite de l'admin, avec un retour de progression
     * Mercure immédiat, exactement comme le reste de indexDocument().
     */
    protected function indexImageDocument(Site $site, Document $document): void
    {
        $siteId = $document->documentable_id ?? $site->id;

        Log::info('Indexation image (document direct, hors crawl)', [
            'document_id' => $document->id,
            'site_id' => $siteId,
            'path' => $document->path,
        ]);

        $this->publishProgress($siteId, "Lecture de l'image…", 10);

        $fullPath = public_path($document->path);

        if (!file_exists($fullPath)) {
            Log::warning('Image document introuvable sur le disque', [
                'document_id' => $document->id,
                'path' => $fullPath,
            ]);

            $this->publishProgress(
                $siteId,
                'Fichier image introuvable',
                100,
                true,
                'indexing_error',
                ['error' => 'file_not_found']
            );
            return;
        }

        $this->publishProgress($siteId, "Analyse vision / OCR de l'image…", 30);

        $documentName = $document->name ?? $document->title ?? $document->original_name ?? basename($document->path);

        $result = $this->imageVisionService->analyzeLocalFile(
            $fullPath,
            alt: $documentName,
            context: null,
        );

        if ($result === null) {
            Log::info('Image document ignorée (décorative, illisible ou trop petite)', [
                'document_id' => $document->id,
            ]);

            $this->publishProgress(
                $siteId,
                'Image sans contenu exploitable (ignorée)',
                100,
                true,
                'indexing_warning'
            );
            return;
        }

        $descriptionText = trim((string) ($result['description'] ?? ''));
        $ocrText = trim((string) ($result['ocr_text'] ?? ''));

        if ($descriptionText === '' && $ocrText === '') {
            $this->publishProgress(
                $siteId,
                'Image sans contenu exploitable (ignorée)',
                100,
                true,
                'indexing_warning'
            );
            return;
        }

        $this->publishProgress($siteId, 'Construction du chunk RAG…', 70);

        $parts = array_filter([
            "Document: {$documentName}",
            $descriptionText !== '' ? "Description de l'image: {$descriptionText}" : null,
            $ocrText !== '' ? "Texte extrait de l'image (OCR): {$ocrText}" : null,
        ]);

        $fullText = implode("\n", $parts);
        $hash = hash('sha256', $fullText . $document->id);

        // 🛑 Idempotence : même logique que pour les autres chunks de documents
        if (Chunk::where('document_id', $document->id)->where('hash', $hash)->exists()) {
            $this->publishProgress($siteId, 'Image déjà indexée', 100, true);
            return;
        }

        $imageUrl = asset($document->path);

        DB::beginTransaction();

        try {
            $embedding = $this->embeddingService->getEmbedding($fullText);

            $metadata = [
                'document_name' => $documentName,
                'document_id'   => $document->id,
                'image_url'     => $imageUrl,
                'is_image'      => true,
                // 🖼️ Mêmes clés que les images de crawl ('title'/'url') pour que
                // ContextBuilder et ImageEntityTransformer traitent les 3 sources
                // d'images de façon identique, sans branchement spécifique.
                'title'         => $documentName,
                'url'           => '/document/' . $document->id,
            ];

            $chunk = Chunk::create([
                'page_id'     => null,
                'site_id'     => $siteId,
                'document_id' => $document->id,
                'source_type' => 'image',
                'text'        => $fullText,
                'priority'    => $this->computeSemanticPriority($fullText, 'image'),
                'metadata'    => $metadata,
                'hash'        => $hash,
            ]);

            $this->lexicalIndexService->ensureIndex($siteId);

            $this->lexicalIndexService->upsertChunk([
                'id'          => (string) $chunk->id,
                'site_id'     => $siteId,
                'document_id' => $document->id,
                'source_type' => 'image',
                'text'        => $fullText,
                'content'     => $fullText,
                'priority'    => $chunk->priority,
                'metadata'    => $metadata,
                'image_url'   => $imageUrl,
            ], $siteId);

            if ($embedding) {
                $this->vectorIndexService->upsertChunk(
                    siteId: $siteId,
                    chunkId: $chunk->id,
                    embedding: $embedding,
                    payload: [
                        'site_id'     => $siteId,
                        'document_id' => $document->id,
                        'source_type' => 'image',
                        'priority'    => $chunk->priority,
                        'metadata'    => $metadata,
                        'image_url'   => $imageUrl,
                        'type'        => 'image',
                    ],
                    collection: "chunks_{$siteId}"
                );
            }

            DB::commit();

            Log::info('Image document indexée', [
                'document_id' => $document->id,
                'chunk_id' => $chunk->id,
            ]);

            $this->publishProgress($siteId, 'Image indexée avec succès', 100, true);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Indexation image (document) échouée', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $this->publishProgress(
                $siteId,
                "Erreur pendant l'indexation de l'image",
                100,
                true,
                'indexing_error',
                ['error' => $e->getMessage()]
            );

            throw $e;
        }
    }
    /**
     * Extrait puis indexe les images EMBARQUÉES d'un document PDF/DOCX/XLSX
     * (schémas, captures d'écran, photos insérées dans un rapport, un contrat,
     * un tableau Excel...). Vient EN COMPLÉMENT du texte déjà indexé par
     * indexDocument() — ne le remplace jamais.
     *
     * Contrairement à indexImageDocument() (image = le document entier), ici
     * un seul document peut produire PLUSIEURS chunks image (un par image
     * embarquée jugée informative).
     */
    protected function indexEmbeddedDocumentImages(Site $site, Document $document): void
    {
        $extension = strtolower((string) $document->extension);

        if (!in_array($extension, ['pdf', 'docx', 'xlsx'], true)) {
            return; // formats non supportés pour l'extraction d'images embarquées
        }

        $siteId = $document->documentable_id ?? $site->id;
        $fullPath = public_path($document->path);

        if (!file_exists($fullPath)) {
            return;
        }

        $embeddedImages = $this->documentImageExtractionService->extract($fullPath, $extension);

        if (empty($embeddedImages)) {
            return;
        }

        $maxImages = (int) config('vision.max_images_per_document', 30);
        $embeddedImages = array_slice($embeddedImages, 0, $maxImages);
        $total = count($embeddedImages);

        $documentName = $document->name ?? $document->title ?? $document->original_name ?? basename($document->path);

        Log::info('Images embarquées détectées', [
            'document_id' => $document->id,
            'extension' => $extension,
            'count' => $total,
        ]);

        $existingHashes = Chunk::query()
            ->where('document_id', $document->id)
            ->where('source_type', 'image')
            ->pluck('hash')
            ->flip()
            ->toArray();

        $index = 0;
        $indexed = 0;

        foreach ($embeddedImages as $imageData) {
            $index++;

            $this->publishProgress(
                $siteId,
                "Analyse des images embarquées ({$index}/{$total})…",
                min(99, 96 + intval(($index / max($total, 1)) * 3))
            );

            $result = $this->imageVisionService->analyzeBytes(
                $imageData['bytes'],
                alt: $documentName,
                context: "Image extraite du document « {$documentName} »",
                logRef: "{$document->id}:{$imageData['filename']}",
            );

            if ($result === null) {
                continue; // décorative, trop petite, ou illisible
            }

            $descriptionText = trim((string) ($result['description'] ?? ''));
            $ocrText = trim((string) ($result['ocr_text'] ?? ''));

            if ($descriptionText === '' && $ocrText === '') {
                continue;
            }

            $parts = array_filter([
                "Document: {$documentName}",
                "Image embarquée: {$imageData['filename']}",
                $descriptionText !== '' ? "Description de l'image: {$descriptionText}" : null,
                $ocrText !== '' ? "Texte extrait de l'image (OCR): {$ocrText}" : null,
            ]);

            $fullText = implode("\n", $parts);
            $hash = hash('sha256', $fullText . $document->id . $imageData['filename']);

            if (isset($existingHashes[$hash])) {
                continue; // déjà indexée lors d'un run précédent
            }

            // Une image embarquée n'a, par nature, aucune URL propre : on la
            // sauvegarde pour que le chatbot puisse l'afficher dans ses réponses.
            $imageUrl = $this->persistEmbeddedImage($document->id, $imageData['bytes'], $imageData['filename']);

            try {
                $embedding = $this->embeddingService->getEmbedding($fullText);

                $metadata = [
                    'document_name'   => $documentName,
                    'document_id'     => $document->id,
                    'image_url'       => $imageUrl,
                    'is_image'        => true,
                    'embedded'        => true,
                    'source_filename' => $imageData['filename'],
                    // 🖼️ Mêmes clés 'title'/'url' que les 2 autres sources d'images
                    'title'           => $documentName,
                    'url'             => '/document/' . $document->id,
                ];

                $chunk = Chunk::create([
                    'page_id'     => null,
                    'site_id'     => $siteId,
                    'document_id' => $document->id,
                    'source_type' => 'image',
                    'text'        => $fullText,
                    'priority'    => $this->computeSemanticPriority($fullText, 'image'),
                    'metadata'    => $metadata,
                    'hash'        => $hash,
                ]);

                $existingHashes[$hash] = true;

                $this->lexicalIndexService->ensureIndex($siteId);

                $this->lexicalIndexService->upsertChunk([
                    'id'          => (string) $chunk->id,
                    'site_id'     => $siteId,
                    'document_id' => $document->id,
                    'source_type' => 'image',
                    'text'        => $fullText,
                    'content'     => $fullText,
                    'priority'    => $chunk->priority,
                    'metadata'    => $metadata,
                    'image_url'   => $imageUrl,
                ], $siteId);

                if ($embedding) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $siteId,
                        chunkId: $chunk->id,
                        embedding: $embedding,
                        payload: [
                            'site_id'     => $siteId,
                            'document_id' => $document->id,
                            'source_type' => 'image',
                            'priority'    => $chunk->priority,
                            'metadata'    => $metadata,
                            'image_url'   => $imageUrl,
                            'type'        => 'image',
                        ],
                        collection: "chunks_{$siteId}"
                    );
                }

                $indexed++;

            } catch (\Throwable $e) {
                Log::warning('Indexation d\'une image embarquée échouée (image ignorée, les autres continuent)', [
                    'document_id' => $document->id,
                    'filename' => $imageData['filename'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        Log::info('Images embarquées indexées', [
            'document_id' => $document->id,
            'total_found' => $total,
            'indexed' => $indexed,
        ]);
    }

    /**
     * Sauvegarde une image extraite d'un conteneur (PDF/DOCX/XLSX) sur le disque
     * public Laravel, pour lui donner une URL stable affichable par le chatbot.
     * Nécessite `php artisan storage:link` si ce n'est pas déjà fait.
     */
    protected function persistEmbeddedImage(string $documentId, string $bytes, string $filename): ?string
    {
        try {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'jpg';
            $storagePath = "document-images/{$documentId}/" . (string) Str::uuid() . '.' . $extension;

            Storage::disk('public')->put($storagePath, $bytes);

            return Storage::disk('public')->url($storagePath);

        } catch (\Throwable $e) {
            Log::warning('Sauvegarde image embarquée échouée', [
                'document_id' => $documentId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    /**
     * Découpe un texte en chunks intelligents avec metadata et overlap
     */
    protected function chunkBySentencesWithMetadata(
        string $text,
        string $documentName,
        string $documentId,
        ?string $siteId,
        int $maxChars,
        int $overlapChars
    ): array {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));
        if (!$sentences || count($sentences) === 0) {
            return [[
                'text' => trim($text),
                'priority' => 50,
                'metadata' => [
                    'document_name' => $documentName,
                    'document_id'   => $documentId,
                    'site_id'       => $siteId,
                    'chunk_index'   => 0,
                ],
            ]];
        }
        $chunks = [];
        $buffer = '';
        $chunkIndex = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            if (mb_strlen($buffer . ' ' . $sentence) <= $maxChars) {
                $buffer .= ' ' . $sentence;
            } else {
                $chunks[] = [
                    'text' => trim($buffer),
                    'priority' => 50 + $chunkIndex, // priorité progressive
                    'metadata' => [
                        'document_name' => $documentName,
                        'document_id'   => $documentId,
                        'site_id'       => $siteId,
                        'chunk_index'   => $chunkIndex,
                    ],
                ];
                $buffer = mb_substr($buffer, -$overlapChars) . ' ' . $sentence;
                $chunkIndex++;
            }
        }

        if (mb_strlen(trim($buffer)) > 0) {
            $chunks[] = [
                'text' => trim($buffer),
                'priority' => 50 + $chunkIndex,
                'metadata' => [
                    'document_name' => $documentName,
                    'document_id'   => $documentId,
                    'site_id'       => $siteId,
                    'chunk_index'   => $chunkIndex,
                ],
            ];
        }

        return $chunks;
    }
    protected function chunkAlreadyExistsForDocument(string $siteId, string $text, ?string $productId = null): bool
    {
        //$hash = sha1($text);
        //$hash = sha1($text . ($identifier ?? ''));

        $hash = hash('sha256', $text . ($productId ?? ''));

        $query = Chunk::where('site_id', $siteId)
            ->where('hash', $hash);

        if ($productId !== null) {
            //$query->where('metadata->identifier', $identifier);
            $query->where('source_type', 'woocommerce')
                ->where('metadata->product_id', $productId);
        }

        return $query->exists();
    }
    protected function resolvePath(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path('public/' . ltrim($path, '/'));
    }
    protected function extractTextFromDocument(string $path, string $extension): string
    {
        $fullPath = $this->resolvePath($path);

        if (!file_exists($fullPath)) {
            Log::warning("Fichier introuvable: {$fullPath}");
            return '';
        }

        return match($extension) {
            'pdf' => $this->extractTextFromPDF($fullPath),
            'doc', 'docx' => $this->extractTextFromWord($fullPath),
            'txt' => $this->extractTextFromTXT($fullPath),
            // 🔥 NEW
            'xls', 'xlsx' => $this->extractTextFromExcel($fullPath),
            'csv' => $this->extractTextFromCSV($fullPath),
            default => '',
        };
    }
    protected function extractTextFromPDF(string $fullPath): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);

            $text = $pdf->getText();

            $text = $this->normalizePdfText($text);

            return $this->normalizeText($text);

        } catch (\Throwable $e) {
            Log::error("Erreur extraction PDF: {$fullPath}", [
                'error' => $e->getMessage()
            ]);

            return '';
        }
    }
    protected function normalizePdfText(string $text): string
    {
        // fix broken line breaks
        $text = preg_replace('/(?<!\n)\n(?!\n)/', ' ', $text);

        // normalize multiple spaces
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // fix column-like spacing (common in PDFs)
        $text = preg_replace('/ {2,}/', ' | ', $text);

        return trim($text);
    }
    protected function extractTextFromWord(string $fullPath): string
    {

        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
            $textParts = [];

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {

                    // Paragraph text
                    if (method_exists($element, 'getText')) {
                        $textParts[] = $element->getText();
                    }

                    // Tables support (important upgrade)
                    if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                        foreach ($element->getRows() as $row) {
                            foreach ($row->getCells() as $cell) {
                                foreach ($cell->getElements() as $cellElement) {
                                    if (method_exists($cellElement, 'getText')) {
                                        $textParts[] = $cellElement->getText();
                                    }
                                }
                            }
                        }
                    }

                }
            }

            return $this->normalizeText(implode("\n", $textParts));
        } catch (\Throwable $e) {
            Log::error("Erreur extraction Word: {$fullPath}", ['error' => $e->getMessage()]);
            return '';
        }
    }
    protected function extractTextFromTXT(string $fullPath): string
    {
        try {
            $content = file_get_contents($fullPath);

            return $this->normalizeText($content);

        } catch (\Throwable $e) {
            Log::error("TXT extraction failed", [
                'file' => $fullPath,
                'error' => $e->getMessage()
            ]);

            return '';
        }
    }
    protected function extractTextFromExcel(string $fullPath): string
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);

            $output = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {

                $sheetName = $sheet->getTitle();
                $output[] = "=== SHEET: {$sheetName} ===";

                $rows = $sheet->toArray(null, true, true, true);

                if (count($rows) > 5000) {
                    $rows = array_slice($rows, 0, 5000);
                }

                if (empty($rows)) continue;

                // headers (first row)
                $headers = array_shift($rows);
                if (!is_array($headers)) {
                    $headers = [];
                }

                $output[] = implode(' | ', $headers);

                foreach ($rows as $row) {

                    if (count(array_filter($row)) === 0) continue;

                    $cleanRow = [];

                    foreach ($headers as $col => $headerName) {
                        $cleanRow[] = $row[$col] ?? '';
                    }

                    $output[] = implode(' | ', $cleanRow);
                }
            }

            return $this->normalizeText(implode("\n", $output));

        } catch (\Throwable $e) {
            Log::error("Excel extraction failed", [
                'file' => $fullPath,
                'error' => $e->getMessage()
            ]);

            return '';
        }
    }
    protected function extractTextFromCSV(string $fullPath): string
    {
        try {
            $delimiter = $this->detectCsvDelimiter($fullPath);

            $rows = [];
            $handle = fopen($fullPath, 'r');

            if (!$handle) return '';

            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {

                if (count(array_filter($data)) === 0) continue;

                $rows[] = implode(' | ', $data);
            }

            fclose($handle);

            return $this->normalizeText(implode("\n", $rows));

        } catch (\Throwable $e) {
            Log::error("CSV extraction failed", [
                'file' => $fullPath,
                'error' => $e->getMessage()
            ]);

            return '';
        }
    }
    protected function detectCsvDelimiter(string $fullPath): string
    {
        $delimiters = [',', ';', "\t", '|'];

        $handle = fopen($fullPath, 'r');
        if (!$handle) return ',';

        $line = fgets($handle);
        fclose($handle);

        if (!$line) return ',';

        $scores = [];

        foreach ($delimiters as $delim) {
            $scores[$delim] = count(str_getcsv($line, $delim));
        }

        // pick delimiter with most columns
        return array_search(max($scores), $scores, true) ?: ',';
    }

    /**
     * Indexe un produit standard dans un document
     */
    public function indexStandardProduct(Product  $product, Document $document, int $priority = 0): void
    {

        $productIndex = $priority + 1;

        $identifier = $product->product_name ?? $product->product_reference ?? 'unknown-product';
        $productId = $product->id; // 🔥 clé réelle

        Log::info('Indexation produit démarrée', [
            'document_id'   => $document->id,
            'product_index' => $productIndex,
            'identifier'    => $identifier,
            'product_id'    => $product->id,
        ]);

        // 🔹 Vérifie si le produit a déjà été indexé avec CE document
        $alreadyIndexedWithDocument = null;
        if ($document !== null) {
            $alreadyIndexedWithDocument = Chunk::where('source_type', 'woocommerce')
                ->where('document_id', $document->id)
                ->where('metadata->product_id', $product->id)
                ->where('metadata->identifier', $identifier)
                ->exists();
        }

        if ($alreadyIndexedWithDocument) {
            Log::info("Produit déjà indexé avec ce document, on passe", [
                'document_id' => $document->id,
                'identifier' => $identifier,
                'product_id'    => $product->id,
            ]);
            return; // NE RIEN FAIRE
        }

        // 🔹 Si c'est un nouveau document et que le produit existe déjà avec un autre document
        $query = Chunk::where('source_type', 'woocommerce')
            ->where('metadata->identifier', $identifier)
            ->where('metadata->product_id', $product->id);

        if ($document) {
            $query->where('document_id', '<>', $document->id);
        }

        $existingChunks = $query->get();

        if ($existingChunks->isNotEmpty()) {
            $chunkIds = $existingChunks->pluck('id')->all();

            $this->vectorIndexService->deleteChunksBatch($chunkIds, collection: "chunks_{$product->site_id}");
            $this->lexicalIndexService->deleteChunksBatch($chunkIds, siteId: $product->site_id);
            //Chunk::whereIn('id', $chunkIds)->delete();
            Chunk::where('product_id', $product->id)->delete();

            Log::info('Ancien produit supprimé pour nouveau document', [
                'document_id' => $document->id,
                'identifier' => $identifier,
                'product_id'    => $product->id,
                'chunks_deleted' => count($chunkIds),
            ]);
        }

        DB::beginTransaction();



        try {
            //=====================================
            //=         PRODUIT SECTION           =
            //=====================================
            // 🔹 NEW: produit → sections
            $structured = $this->productToSections($product);
            // 🔹 Fake Page pour réutiliser ton pipeline existant
            $page = new Page([
                'id' => (string) Str::uuid(),
                'site_id' => $product->site_id,
                'title' => $product->product_name ?? 'Produit',
                'url' => $product->product_url ?? null,
                'content' => json_encode($structured),
                'source' => 'product',
            ]);
            // 🔹 Générer les chunks via ton moteur existant
            $chunks = $this->buildChunksFromSections($page, $structured['sections']);

            $splitValues = fn(string $value): array => array_filter(array_map('trim', preg_split('/[,;|]/', trim($value))));

            $this->lexicalIndexService->ensureIndex($product->site_id);
            // 🔹 Sauvegarde + embeddings
            foreach ($chunks as $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];

                if (trim($textChunk) === '') continue;
                if ($this->chunkAlreadyExistsForDocument(siteId: $product->site_id, text: $textChunk, productId: $productId)) continue;

                try {
                    //$embedding = $this->embeddingService->getEmbedding($textChunk);
                    if (!($chunkData['no_embedding'] ?? false)) {
                        $embedding = $this->embeddingService->getEmbedding($textChunk);
                    } else {
                        $embedding = null;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Embedding échoué (section produit)", [
                        'document_id' => $document->id,
                        'product_id' => $product->id,
                        'preview' => mb_substr($textChunk, 0, 100),
                    ]);
                    continue;
                }

                $hash = hash('sha256', $textChunk . $productId);
                $chunk = Chunk::create([
                    'document_id' => $document->id,
                    'product_id'  => $product->id,
                    'site_id'     => $product->site_id,
                    'source_type' => 'woocommerce',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'metadata'    => [
                        'type' => 'section',
                        'identifier' => $identifier,
                        'product_id' => $productId, // 🔥 AJOUT
                        //'overview' => $this->buildProductOverview($product),
                        //'field' => $section['h2'] ?? null,
                    ],
                    'hash' => $hash,
                ]);

                $aliasesMap = [];

                foreach ($product->toIndexableArray() as $field => $value) {
                    if (!$value) continue;

                    foreach ($splitValues($value) as $v) {
                        if ($v === '') continue;

                        foreach ($this->generateStatisticalAliasesLexical($field, $v) as $alias) {
                            $aliasesMap[$alias] = true; // anti-dup direct
                        }
                    }
                }

                $aliases = array_keys($aliasesMap);

                $this->lexicalIndexService->upsertChunk([
                    'id' => (string) $chunk->id,
                    'site_id' => $chunk->site_id,
                    'product_id'  => $productId,
                    'document_id' => $chunk->document_id,
                    'source_type' => $chunk->source_type,
                    'text' => $chunk->text,
                    'content' => $chunk->text, // 🔥 duplication stratégique
                    'aliases' => $aliases, // 🔥 ici
                    'priority' => $chunk->priority,
                    'metadata' => $chunk->metadata,
                ], $chunk->site_id);

                if ($embedding) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $chunk->site_id,
                        chunkId: $chunk->id,
                        embedding: $embedding,
                        payload: [
                            'site_id'     => $chunk->site_id,
                            'document_id' => $chunk->document_id,
                            'source_type' => $chunk->source_type,
                            'priority'    => $chunk->priority,
                            'product_id'  => $productId, // 🔥 ICI
                            'metadata' => $chunk->metadata,
                            'has_overview' => true,
                        ],
                        collection: "chunks_{$chunk->site_id}"
                    );
                }

            }

            // 🔹 2️⃣ Chunks granulaires avec alias et synonymes
            //$splitValues = fn(string $value): array => array_filter(array_map('trim', preg_split('/[,;|]/', trim($value))));

            foreach ($product->toIndexableArray() as $field => $value) {
                if (!$value) continue;

                foreach ($splitValues($value) as $v) {
                    if ($v === '') continue;

                    $aliases = $this->generateStatisticalAliases($field, $v);

                    foreach ($aliases as $aliasText) {
                        if (strlen($aliasText) < 3) continue;
                        if (str_word_count($aliasText) === 1 && strlen($aliasText) < 4) continue;
                        if ($this->chunkAlreadyExistsForDocument(siteId: $product->site_id, text: $aliasText, productId: $productId)) continue;

                        /*try {
                            if (trim($aliasText) === '') continue;
                            $embedding = $this->embeddingService->getEmbedding($aliasText);
                        } catch (\Throwable $e) {
                            Log::warning("Embedding échoué pour chunk produit", [
                                'document_id' => $document->id,
                                'product_index' => $productIndex,
                                'product_id'  => $productId,
                                'chunk_preview' => mb_substr($aliasText, 0, 100),
                                'error' => $e->getMessage(),
                            ]);
                            continue;
                        }*/

                        $hash = hash('sha256', $aliasText . $productId);

                        Chunk::create([
                            'document_id' => $document->id,
                            'product_id'  => $productId,
                            'site_id'     => $product->site_id,
                            'source_type' => 'woocommerce',
                            'text'        => $aliasText,
                            'priority'    => $this->computeSemanticPriority(
                                $aliasText,
                                type: 'alias',
                                field: $field
                            ),
                            'metadata'    => [
                                'type' => 'statistical_alias',
                                'field' => $field,
                                'value' => $v,
                                'identifier' => $identifier,
                                'product_id' => $productId, // 🔥 AJOUT
                                'product_index' => $productIndex,
                            ],
                            'hash' => $hash,
                        ]);

                        /*if ($chunk) {
                            $this->vectorIndexService->upsertChunk(
                                siteId: $product->site_id,
                                chunkId: $chunk->id,
                                embedding: $embedding,
                                payload: [
                                    'site_id'     => $chunk->site_id,
                                    'page_id'     => $chunk->page_id,
                                    'document_id' => $chunk->document_id,
                                    'source_type' => $chunk->source_type,
                                    'priority'    => $chunk->priority,
                                    'product_id'  => $productId, // 🔥 ICI
                                    'metadata' => $chunk->metadata,
                                    'has_overview' => false,
                                ],
                                collection: "chunks_{$chunk->site_id}"
                            );
                        }*/
                    }
                }
            }

            $page->delete();

            DB::commit();

            Log::info('Produit indexé avec succès', [
                'document_id'    => $document->id,
                'product_id'     => $product->id,
                'product_index'  => $productIndex,
                'identifier'     => $identifier,
                'chunks_created' => count($chunks),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Indexation produit échouée", [
                'document_id' => $document->id,
                'product_index' => $productIndex,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    /**
     * Génère des alias et synonymes pour un champ produit
     */
    protected function generateStatisticalAliases(string $label, string $value): array
    {
        $aliases = [];
        $label = $this->normalizeText($label);
        //$value = $this->normalizeText($value);
        //$label = ucfirst(str_replace('_', ' ', $label));
        $value = trim($value);

        if ($value === '') return [];

        // Forme complète
        $aliases[] = "{$label}: {$value}";

        // Valeur seule
        //$aliases[] = $value;

        // Tokens individuels
        if (strlen($value) > 3 && str_word_count($value) <= 6) {
            $aliases[] = $value;
        }

        // Synonymes depuis FieldSynonym
        $synonyms = FieldSynonym::where('field_key', $label)
            ->pluck('synonym')
            ->toArray();

        if (!empty($synonyms)) {
            shuffle($synonyms);
            $count = min(max(5, rand(5, 7)), count($synonyms));
            $selectedSynonyms = array_slice($synonyms, 0, $count);

            foreach ($selectedSynonyms as $syn) {
                $syn = $this->normalizeText($syn);
                if ($syn !== '' && !in_array($syn, $aliases)) $aliases[] = $syn;
            }
        }

        return array_unique($aliases);
    }
    protected function productToSections(Product $product): array
    {
        $sections = [];
        $identifier = $product['identifier'] ?? $product['product_name'] ?? $product['product_reference'] ?? 'unknown-product';

        $h1 = $product['product_name'] ?? 'Produit';

        foreach ($product->toIndexableArray() as $field => $value) {
            if (!$value) continue;

            $values = is_array($value)
                ? $value
                : array_filter(array_map('trim', preg_split('/[,;|]/', $value)));

            if (empty($values)) continue;

            $label = ucfirst(str_replace('_', ' ', $field));

            $sections[] = [
                'h1' => $h1,
                'h2' => $label,
                'content' => implode(', ', $values),
                'weight' => $this->getFieldWeight($field),
            ];
        }

        $sections[] = [
            'h1' => $h1,
            'h2' => 'Overview',
            'content' => $this->buildProductOverview($product),
            'weight' => 3.5,
            'no_embedding' => true,
        ];

        return [
            'sections' => $sections,
            'type' => 'product',
            'meta' => [
                'identifier' => $identifier,
            ]
        ];
    }
    protected function getFieldWeight(string $field): float
    {
        return match ($field) {
            'product_name' => 3,
            'description' => 2.5,
            'features' => 2,
            'short_description' => 2,
            'product_category' => 1.8,
            'brand' => 1.5,
            'price' => 1.3,
            'discount_price' => 1.3,
            default => 1,
        };
    }
    protected function buildProductOverview(Product $product): string
    {
        $parts = [];

        foreach ($product->toIndexableArray() as $key => $value) {
            if (!$value) continue;

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
        }

        return implode('. ', $parts);
    }
    protected function computeSemanticPriority(
        string $text,
        string $type = 'section',
        ?string $field = null
    ): int {
        $score = 50; // base neutre (IMPORTANT)

        // 🔹 Type = signal léger (pas dominant)
        if ($type === 'section') {
            $score += 5;
        } elseif ($type === 'alias') {
            $score -= 5;
        } elseif ($type === 'image') {
            // Une image indexée a déjà passé le filtre "informative" (OCR/description
            // non vides) -> souvent un schéma, une capture d'écran ou un tableau,
            // donc un signal légèrement plus fort qu'un paragraphe générique.
            $score += 7;
        }

        // 🔹 Champ = léger hint (pas ranking global)
        if ($field) {
            $fieldBoost = match ($field) {
                'product_name', 'title' => 8,
                'description' => 5,
                'features' => 4,
                'category' => 3,
                'brand' => 2,
                default => 0
            };

            $score += $fieldBoost;
        }

        // 🔹 Longueur (évite bruit extrême)
        $len = str_word_count($text);

        if ($len < 3) $score -= 10;
        if ($len > 80) $score -= 5;

        // 🔹 Clamp strict
        return max(10, min(90, $score));
    }
    protected function generateStatisticalAliasesLexical(string $label, string $value): array
    {
        $aliases = [];

        //$label = $this->normalizeText($label);
        $label = trim($label);
        $value = trim($value);

        if ($value === '') return [];

        $group = $this->getFieldGroup($label);
        $boost = $this->getFieldBoost($label);

        // 0. fallback
        $aliases[] = "raw|{$label}|{$value}";

        // 1. CORE SIGNAL (structuré)
        $aliases[] = "core|{$group}|{$label}|{$value}|b{$boost}";

        // 2. VALUE ONLY (signal brut)
        if (strlen($value) > 3 && str_word_count($value) <= 6) {
            $aliases[] = "val|{$label}|{$value}";
        }

        // 3. SYNONYMS (cached)
        static $synonymCache = [];

        $synonyms = $synonymCache[$label] ??= FieldSynonym::where('field_key', $label)
            ->pluck('synonym')
            ->toArray();

        foreach (array_slice($synonyms, 0, 5) as $syn) {
            $syn = $this->normalizeText($syn);
            if ($syn === '') continue;
            if (similar_text($syn, $value) > 80) continue; // anti redondance
            $aliases[] = "syn|{$group}|{$label}|{$syn}";
        }

        $tokens = preg_split('/\s+/', strtolower($value));
        foreach ($tokens as $t) {
            if (strlen($t) > 2) {
                $aliases[] = "tok|{$label}|{$t}";
            }
        }

        return $aliases;
    }
    protected function getFieldGroup(string $field): string
    {
        return match ($field) {
            'product_name', 'product_reference', 'product_category', 'description' => 'core',

            'price', 'price_min', 'price_max', 'discount_price', 'currency' => 'pricing',

            'brand', 'tags', 'keywords', 'features' => 'descriptive',

            'stock_status', 'stock_quantity', 'availability', 'colors', 'materials' => 'logistics',

            default => 'meta',
        };
    }
    protected function getFieldBoost(string $field): int
    {
        return match ($field) {
            'product_name' => 10,
            'product_reference' => 9,
            'product_category' => 8,

            'price', 'discount_price' => 7,

            'brand' => 8,
            'keywords' => 7,
            'features' => 6,

            'availability' => 5,

            default => 3,
        };
    }
    protected function publishProgress(
        string $siteId,
        string $message,
        int $progress,
        bool $done = false,
        string $type = 'indexing_progress',
        array $extra = []
    ): void {

        $this->mercureService->post(
            "site/{$siteId}/knowledge/indexing",
            array_merge([
                'type' => $type,
                'progress' => $progress,
                'message' => $message,
                'done' => $done,
            ], $extra)
        );
    }
}
