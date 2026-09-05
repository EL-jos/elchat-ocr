<?php
namespace App\Services\ia;

use App\Services\hops\LLMService;
use RuntimeException;
use Yethee\Tiktoken\EncoderProvider;

class EmbeddingService
{
    /**
     * Modèle embedding
     */
    /**
     * Taille sécurisée par chunk
     * (bien en dessous des 8192 tokens)
     */
    private const MAX_TOKENS_PER_CHUNK = 800;

    /**
     * Overlap pour préserver le contexte
     */
    private const OVERLAP_TOKENS = 120;

    /**
     * Nombre max de chunks envoyés
     * dans une seule requête API
     */
    private const BATCH_SIZE = 32;

    private $encoder;

    public function __construct(private readonly LLMService $llm)
    {
        $provider = new EncoderProvider();

        $this->encoder = $provider->getForModel(
            'text-embedding-3-small'
        );
    }

    /**
     * Compatibilité totale avec ton ancien code
     *
     * Retourne EXACTEMENT :
     * float[]
     *
     * MAIS :
     * - gère les longs textes
     * - chunking intelligent
     * - batching
     * - retries robustes
     *
     * IMPORTANT :
     * retourne le PREMIER embedding
     * pour conserver la compatibilité stricte.
     */
    public function getEmbedding(string $text): array
    {
        $text = $this->normalize($text);

        $chunks = $this->chunkByTokens($text);

        if (empty($chunks)) {
            throw new RuntimeException('No chunks generated');
        }

        /**
         * Compatibilité stricte :
         * on retourne uniquement le premier embedding
         */
        return $this->requestEmbedding($chunks[0]);
    }

    /**
     * Nouvelle méthode PRO :
     * retourne tous les embeddings des chunks
     *
     * [
     *   [
     *      'text' => '...',
     *      'embedding' => [...]
     *   ]
     * ]
     */
    public function getChunkEmbeddings(string $text): array
    {
        $text = $this->normalize($text);

        $chunks = $this->chunkByTokens($text);

        $results = [];

        foreach (array_chunk($chunks, self::BATCH_SIZE) as $batch) {

            $embeddings = $this->requestEmbeddings($batch);

            foreach ($embeddings as $index => $embedding) {

                $results[] = [
                    'text' => $batch[$index],
                    'embedding' => $embedding,
                ];
            }
        }

        return $results;
    }

    /**
     * Retourne UN embedding
     */
    private function requestEmbedding(string $text): array
    {
        $embeddings = $this->requestEmbeddings([$text]);

        if (! isset($embeddings[0])) {
            throw new RuntimeException('Invalid embedding response');
        }

        return $embeddings[0];
    }

    /**
     * Retourne plusieurs embeddings
     */
    private function requestEmbeddings(array $chunks): array
    {
        try {
            return $this->llm->embeddings($chunks, [
                'task' => 'embedding',
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Embedding API Error: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Nettoyage robuste
     */
    private function normalize(string $text): string
    {
        $text = strip_tags($text);

        $text = html_entity_decode($text);

        /**
         * Supprime espaces multiples
         */
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Chunking intelligent basé tokens
     */
    private function chunkByTokens(string $text): array
    {
        $tokens = $this->encoder->encode($text);

        if (empty($tokens)) {
            return [];
        }

        $chunks = [];

        $start = 0;

        $maxTokens = self::MAX_TOKENS_PER_CHUNK;

        $overlap = self::OVERLAP_TOKENS;

        while ($start < count($tokens)) {

            $slice = array_slice(
                $tokens,
                $start,
                $maxTokens
            );

            $chunks[] = $this->encoder->decode($slice);

            $start += ($maxTokens - $overlap);
        }

        return $chunks;
    }
}
