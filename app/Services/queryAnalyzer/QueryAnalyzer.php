<?php

namespace App\Services\queryAnalyzer;

use App\Models\Conversation;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueryAnalyzer
{
    public function __construct(

    ) {}
    public function analyze(string $question, Conversation $conversation): QueryPlan
    {
        $prompt = $this->buildPrompt($question, $conversation);

        $maxValidationRetries = 5;

        $attempt = 0;

        $lastResponse = null;

        while ($attempt < $maxValidationRetries) {

            $attempt++;

            Log::info('QueryAnalyzer validation pipeline started', [
                'attempt' => $attempt,
                'question' => substr($question, 0, 120)
            ]);

            /*
            |--------------------------------------------------------------------------
            | Build prompt
            |--------------------------------------------------------------------------
            */

            $effectivePrompt = $attempt === 1
                ? $prompt
                : $this->buildRepairPrompt(
                    $prompt,
                    $lastResponse ?? ''
                );

            /*
            |--------------------------------------------------------------------------
            | LLM call
            |--------------------------------------------------------------------------
            */

            $response = $this->callLLMForQueryPlan(
                $effectivePrompt,
                $question
            );

            $lastResponse = $response;

            /*
            |--------------------------------------------------------------------------
            | Extract JSON
            |--------------------------------------------------------------------------
            */

            $data = $this->extractJson($response);

            if (!$data) {

                Log::warning('QueryAnalyzer invalid JSON', [
                    'attempt' => $attempt,
                    'response' => substr($response, 0, 1000)
                ]);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize response
            |--------------------------------------------------------------------------
            */

            $data = $this->normalizeResponse($data);

            /*
            |--------------------------------------------------------------------------
            | Structure validation
            |--------------------------------------------------------------------------
            */

            if (!$this->validateResponseStructure($data)) {

                Log::warning('QueryAnalyzer structure validation failed', [
                    'attempt' => $attempt,
                    'data' => $data
                ]);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Enum validation
            |--------------------------------------------------------------------------
            */

            if (!$this->validateEnums($data)) {

                Log::warning('QueryAnalyzer enum validation failed', [
                    'attempt' => $attempt,
                    'intent' => $data['intent'] ?? null,
                    'query_type' => $data['query_type'] ?? null,
                    'strategy' => $data['search_strategy'] ?? null
                ]);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Business rules validation
            |--------------------------------------------------------------------------
            */

            if (!$this->validateBusinessRules($data)) {

                Log::warning('QueryAnalyzer business validation failed', [
                    'attempt' => $attempt,
                    'data' => $data
                ]);

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            Log::info('QueryAnalyzer success', [
                'attempt' => $attempt,
                'intent' => $data['intent'],
                'strategy' => $data['search_strategy'],
                'query_count' => count($data['search_queries']),
                'sub_query_count' => count($data['sub_queries'])
            ]);

            return $this->mapToQueryPlan($data);
        }

        /*
        |--------------------------------------------------------------------------
        | TOTAL FAILURE FALLBACK
        |--------------------------------------------------------------------------
        */

        Log::error('QueryAnalyzer completely failed after retries', [
            'question' => $question,
            'attempts' => $maxValidationRetries
        ]);

        return $this->mapToQueryPlan(
            $this->buildFallbackPlan($question)
        );
    }
    private function buildPrompt(string $question, Conversation $conversation): string
    {
        $summary = $conversation->summary ?? "";

        return <<<PROMPT
        You are an expert Query Analyzer for an enterprise-grade AI retrieval system.

        Your role is to convert a user question into a precise, optimized, and structured retrieval plan for a vector search pipeline.

        You must produce highly reliable, deterministic, and minimal-noise outputs.

        =================
        INPUT CONTEXT
        =================

        Conversation summary:
        {$summary}

        User question:
        {$question}

        =================
        OBJECTIVES
        =================

        Analyze and extract:

        1. User intent (strict classification)
        2. Key entities (typed and normalized)
        3. Optimized semantic search queries
        4. Sub-queries if multiple information needs exist
        5. Filters (ONLY if explicitly or implicitly required)
        6. Retrieval strategy (based on query complexity)
        7. Constraints (explicit or implicit limits such as budget, time, region, compliance, performance, etc.)

        =================
        OUTPUT FORMAT
        =================

        Return ONLY valid JSON. No explanations.

        {
          "clean_query": "...",

          "search_queries": [],
          "sub_queries": [],

          "entities": [
            {
              "type": "company | product | feature | plan | location | date | metric | other",
              "value": "normalized entity"
            }
          ],

          "intent": "information | pricing | comparison | navigation | transactional | support | lead | booking | download",

          "query_type": "factual | exploratory | transactional",

          "needs_conversation_context": true | false,

          "filters": {
            "date_range": null,
            "product": null,
            "plan": null,
            "language": null,
            "other": {}
          },

          "top_k": 30,

          "search_strategy": "single | multi_query | decomposition",

          "constraints": []
        }

        =================
        STRICT RULES
        =================

        - Output must be valid JSON ONLY
        - No hallucinated data
        - No explanations or comments
        - Keep queries short and embedding-optimized (5–12 words ideal)
        - Prefer keyword-rich semantic queries over full sentences
        - Avoid redundancy in search_queries
        - Max 5 search_queries
        - Max 5 sub_queries

        =================
        OVERRIDE RULE (CRITICAL)
        =================

        If intent = "comparison":
        - search_strategy MUST be "decomposition"
        - sub_queries MUST NOT be empty

        =================
        IMPLICIT CONSTRAINT DETECTION (CRITICAL)
        =================

        If the query includes:
        - sleeping position (side, back, stomach)
        - comfort preferences
        - usage context

        THEN:
        - MUST add them into "constraints"

        =================
        SUB-QUERY GENERATION RULES (MANDATORY FOR COMPARISON)
        =================

        For comparison queries, generate at least 3 sub_queries:

        1. "'product A' caractéristiques"
        2. "'product B' caractéristiques"
        3. "'constraint' recommandation produit"

        Sub_queries must NOT be empty.

        =================
        QUERY OPTIMIZATION RULES
        =================

        - Expand implicit meaning when helpful
        - Add synonyms when it improves recall
        - Remove stopwords unless necessary
        - Normalize terminology (e.g. "pricing" instead of "how much does it cost")
        - Use domain-relevant keywords

        =================
        SUB-QUERY RULES
        =================

        Use sub_queries ONLY if:
        - multiple intents exist
        - comparison is requested
        - question requires decomposition

        Otherwise return empty array.

        =================
        FILTER RULES
        =================

        Use filters ONLY when:
        - explicitly requested (e.g. "in 2024", "for startups")
        - clearly implied (e.g. "latest", "enterprise plan")

        Otherwise keep filters empty.

        =================
        CONSTRAINTS RULES
        =================

        Extract constraints ONLY when:
        - explicitly stated (e.g. "under $100", "in Europe", "GDPR compliant")
        - clearly implied (e.g. "fastest", "low latency", "high accuracy")

        =================
        SEARCH STRATEGY RULES
        =================

        - single → simple factual query
        - multi_query → when query benefits from semantic variations
        - decomposition → when multiple distinct questions or comparison

        =================
        CONTEXT USAGE
        =================

        Set needs_conversation_context = true ONLY if:
        - the query depends on previous conversation
        - contains references like "it", "that", "this", "again"

        Otherwise false.

        =================
        INTENT DEFINITIONS
        =================

        information → general informational query
        pricing → cost, plans, fees
        comparison → comparing options
        navigation → locating something
        transactional → buying or subscribing
        support → troubleshooting
        lead → request demo/contact
        booking → scheduling
        download → requesting a resource

        =================
        FAILSAFE
        =================

        If the query is vague or underspecified:
        - infer best possible clean_query
        - use multi_query strategy
        - avoid filters unless certain
        PROMPT;
    }
    private function mapToQueryPlan(array $data): QueryPlan
    {
        $plan = new QueryPlan();

        $plan->cleanQuery = $data['clean_query'] ?? '';

        $plan->searchQueries = is_array($data['search_queries'] ?? null)
            ? $data['search_queries']
            : [];

        $plan->subQueries = is_array($data['sub_queries'] ?? null)
            ? $data['sub_queries']
            : [];

        $plan->entities = is_array($data['entities'] ?? null)
            ? $data['entities']
            : [];

        $plan->intent = $data['intent'] ?? 'information';

        $plan->queryType = $data['query_type'] ?? 'factual';

        $plan->needsConversationContext = $data['needs_conversation_context'] ?? false;

        $plan->filters = is_array($data['filters'] ?? null)
            ? $data['filters']
            : [];

        $plan->topK = intval($data['top_k'] ?? 30);

        $plan->searchStrategy = $data['search_strategy'] ?? 'single';

        $plan->constraints = is_array($data['constraints'] ?? null)
            ? $data['constraints']
            : [];

        return $plan;
    }
    private function callLLMForQueryPlan(string $prompt, string $question): string
    {
        $maxRetries = 4;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            try {

                Log::info("QueryAnalyzer LLM call (attempt {$attempt})", [
                    "question" => substr($question, 0, 120)
                ]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->post('https://openrouter.ai/api/v1/chat/completions', [

                    "model" => "openai/gpt-4.1-mini",

                    "messages" => [
                        [
                            "role" => "system",
                            "content" => "You are a query planning engine for a semantic search system.
                            Return ONLY valid JSON."
                        ],
                        [
                            "role" => "user",
                            "content" => $prompt
                        ]
                    ],

                    "temperature" => 0.2,
                    "max_tokens" => 400
                ]);

                if (!$response->successful()) {

                    Log::warning("QueryAnalyzer HTTP error", [
                        "status" => $response->status(),
                        "body" => $response->body()
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }

                    break;
                }

                $data = $response->json();

                if (
                    isset($data['choices'][0]['message']['content'])
                ) {

                    $content = $data['choices'][0]['message']['content'];

                    Log::info("QueryAnalyzer response received", [
                        "length" => strlen($content)
                    ]);

                    return $content;
                }

            } catch (Exception $e) {

                Log::warning("QueryAnalyzer exception", [
                    "error" => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }
        }

        Log::error("QueryAnalyzer failed after retries");

        return json_encode([
            "clean_query" => $question,
            "search_queries" => [$question],
            "sub_queries" => [],
            "entities" => [],
            "intent" => "information",
            "query_type" => "factual",
            "needs_conversation_context" => false,
            "filters" => [],
            "top_k" => 30,
            "search_strategy" => "single",
            "constraints" => []
        ]);
    }
    private function extractJson(string $response): ?array
    {
        $response = trim($response);

        // remove markdown fences
        $response = preg_replace('/```json|```/', '', $response);

        preg_match('/\{(?:[^{}]|(?R))*\}/s', $response, $matches);

        if (empty($matches[0])) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {

            Log::warning("JSON decode failed", [
                'error' => json_last_error_msg()
            ]);

            return null;
        }

        return $decoded;
    }
    private function validateResponseStructure(?array $data): bool
    {
        if (!$data) {
            return false;
        }

        $requiredFields = [
            'clean_query',
            'search_queries',
            'sub_queries',
            'entities',
            'intent',
            'query_type',
            'needs_conversation_context',
            'filters',
            'top_k',
            'search_strategy',
            'constraints'
        ];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {

                Log::warning("Missing field in QueryAnalyzer response", [
                    'field' => $field
                ]);

                return false;
            }
        }

        if (!is_string($data['clean_query'])) {
            return false;
        }

        if (!is_array($data['search_queries'])) {
            return false;
        }

        if (!is_array($data['sub_queries'])) {
            return false;
        }

        if (!is_array($data['entities'])) {
            return false;
        }

        if (!is_array($data['filters'])) {
            return false;
        }

        if (!is_array($data['constraints'])) {
            return false;
        }

        if (!is_bool($data['needs_conversation_context'])) {
            return false;
        }

        if (!is_int($data['top_k'])) {
            return false;
        }

        return true;
    }
    private function validateEnums(array $data): bool
    {
        $validIntents = [
            'information',
            'pricing',
            'comparison',
            'navigation',
            'transactional',
            'support',
            'lead',
            'booking',
            'download'
        ];

        $validQueryTypes = [
            'factual',
            'exploratory',
            'transactional'
        ];

        $validStrategies = [
            'single',
            'multi_query',
            'decomposition'
        ];

        if (!in_array($data['intent'], $validIntents)) {
            return false;
        }

        if (!in_array($data['query_type'], $validQueryTypes)) {
            return false;
        }

        if (!in_array($data['search_strategy'], $validStrategies)) {
            return false;
        }

        return true;
    }
    private function validateBusinessRules(array $data): bool
    {
        // comparison => decomposition obligatoire
        if (
            $data['intent'] === 'comparison'
            && $data['search_strategy'] !== 'decomposition'
        ) {
            return false;
        }

        // comparison => subqueries obligatoires
        if (
            $data['intent'] === 'comparison'
            && empty($data['sub_queries'])
        ) {
            return false;
        }

        // max limits
        if (count($data['search_queries']) > 5) {
            return false;
        }

        if (count($data['sub_queries']) > 5) {
            return false;
        }

        // top_k guard
        if ($data['top_k'] < 1 || $data['top_k'] > 50) {
            return false;
        }

        return true;
    }
    private function buildRepairPrompt( string $originalPrompt, string $invalidResponse ): string {

        return $originalPrompt . "

CRITICAL ERROR:
Your previous response was INVALID.

Previous invalid response:
{$invalidResponse}

You MUST now:
- Return STRICT VALID JSON
- Respect ALL required fields
- Respect ALL enum values
- Do NOT add markdown
- Do NOT add explanations
- Do NOT omit fields
";
    }
    private function normalizeResponse(array $data): array
    {
        $data['intent'] = strtolower(trim($data['intent']));
        $data['query_type'] = strtolower(trim($data['query_type']));
        $data['search_strategy'] = strtolower(trim($data['search_strategy']));

        $data['top_k'] = max(
            1,
            min(50, intval($data['top_k']))
        );

        $data['search_queries'] = array_values(
            array_unique($data['search_queries'])
        );

        return $data;
    }
    private function buildFallbackPlan(string $question): array
    {
        return [

            "clean_query" => $question,

            "search_queries" => [
                $question
            ],

            "sub_queries" => [],

            "entities" => [],

            "intent" => "information",

            "query_type" => "factual",

            "needs_conversation_context" => false,

            "filters" => [],

            "top_k" => 20,

            "search_strategy" => "single",

            "constraints" => []
        ];
    }
}
