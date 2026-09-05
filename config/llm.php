<?php

return [
    /*
     * Registre unique des modèles utilisés par ELChat.
     *
     * Pour changer un modèle, modifier uniquement la tâche concernée ci-
     * dessous. `fallback_model` peut être mis à null pour désactiver le
     * basculement automatique sur une tâche donnée.
     */
    'provider' => [
        'base_url' => env('LLM_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('OPENROUTER_API_KEY'),
        // Valeurs par défaut, surchargeables tâche par tâche ci-dessous.
        'connect_timeout' => (int) env('LLM_CONNECT_TIMEOUT', 10),
        'request_timeout' => (int) env('LLM_REQUEST_TIMEOUT', env('LLM_TIMEOUT', 120)),
        'max_retries' => (int) env('LLM_MAX_RETRIES', 3),
        // Retries are reserved for 429, 5xx, timeouts and transient network
        // failures. 4xx errors other than 429 switch model immediately.
        'retry_base_delay_ms' => (int) env('LLM_RETRY_BASE_DELAY_MS', 200),
        'retry_max_delay_ms' => (int) env('LLM_RETRY_MAX_DELAY_MS', 5000),
        'retry_jitter_percent' => (int) env('LLM_RETRY_JITTER_PERCENT', 20),
        'max_response_bytes' => (int) env('LLM_MAX_RESPONSE_BYTES', 4194304),
        'max_json_chars' => (int) env('LLM_MAX_JSON_CHARS', 1048576),
    ],

    // Budget du retrieval itératif. Deux passages suffisent dans la majorité
    // des cas et évitent qu'une question ambiguë monopolise les workers.
    // La valeur reste surchargeable par environnement si un tenant nécessite
    // ponctuellement un budget différent.
    'multi_hop' => [
        'max_hops' => max(1, (int) env('LLM_MULTI_HOP_MAX_HOPS', 2)),
    ],

    'tasks' => [
        // Conversation principale du widget.
        'chat' => [
            'model' => env('LLM_CHAT_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_CHAT_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        'chat_summary' => [
            'model' => env('LLM_CHAT_SUMMARY_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_CHAT_SUMMARY_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => 30,
        ],

        'social_lead_rewrite' => [
            'model' => env('LLM_SOCIAL_LEAD_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_SOCIAL_LEAD_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => 15,
        ],

        'follow_up_detection' => [
            'model' => env('LLM_FOLLOW_UP_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_FOLLOW_UP_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => 15,
        ],

        'conversation_rewrite' => [
            'model' => env('LLM_REWRITE_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_REWRITE_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => 15,
        ],

        // ============================================================
        // QUERY / RETRIEVAL
        // ============================================================

        'retrieval_query_expansion' => [
            'model' => env('LLM_QUERY_EXPANSION_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_QUERY_EXPANSION_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        'query_analysis' => [
            'model' => env('LLM_QUERY_ANALYSIS_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_QUERY_ANALYSIS_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            // Analyse JSON courte : éviter qu'un modèle indisponible bloque
            // toute la réponse avant le basculement vers le secours.
            'connect_timeout' => 5,
            'request_timeout' => 15,
        ],

        // ============================================================
        // MULTI-HOP
        // ============================================================

        'multi_hop_decision' => [
            'model' => env('LLM_MULTI_HOP_DECISION_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_MULTI_HOP_DECISION_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'multi_hop_objective_extraction' => [
            'model' => env('LLM_MULTI_HOP_OBJECTIVE_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_MULTI_HOP_OBJECTIVE_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'multi_hop_thought' => [
            'model' => env('LLM_MULTI_HOP_THOUGHT_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_MULTI_HOP_THOUGHT_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'multi_hop_query' => [
            'model' => env('LLM_MULTI_HOP_QUERY_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_MULTI_HOP_QUERY_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'multi_hop_summary' => [
            'model' => env('LLM_MULTI_HOP_SUMMARY_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_MULTI_HOP_SUMMARY_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        // ============================================================
        // ANSWER QUALITY
        // ============================================================

        'answer_validation' => [
            'model' => env('LLM_ANSWER_VALIDATION_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_ANSWER_VALIDATION_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'answer_relevance' => [
            'model' => env('LLM_ANSWER_RELEVANCE_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_ANSWER_RELEVANCE_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'answer_grounding' => [
            'model' => env('LLM_ANSWER_GROUNDING_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_ANSWER_GROUNDING_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'answer_consistency' => [
            'model' => env('LLM_ANSWER_CONSISTENCY_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_ANSWER_CONSISTENCY_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        // ============================================================
        // RAG
        // ============================================================

        'rag_context_compression' => [
            'model' => env('LLM_RAG_COMPRESSION_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_RAG_COMPRESSION_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'rag_answer' => [
            'model' => env('LLM_RAG_ANSWER_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_RAG_ANSWER_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'rag_query_generation' => [
            'model' => env('LLM_RAG_QUERY_GENERATION_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_RAG_QUERY_GENERATION_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'rag_evaluation_judge' => [
            'model' => env('LLM_RAG_EVALUATION_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_RAG_EVALUATION_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'rag_recommendation' => [
            'model' => env('LLM_RAG_RECOMMENDATION_MODEL', 'openai/gpt-4.1-mini'),
            'fallback_model' => env('LLM_RAG_RECOMMENDATION_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],
        'rag_rerank' => [
            'model' => env('LLM_RAG_RERANK_MODEL', 'cohere/rerank-v3.5'),
            // Les modèles de reranking ne sont pas toujours interchangeables.
            'fallback_model' => env('LLM_RAG_RERANK_FALLBACK_MODEL'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        // ============================================================
        // MULTIMODAL / EMBEDDINGS
        // ============================================================

        'vision' => [
            'model' => env('LLM_VISION_MODEL', env('OPENROUTER_VISION_MODEL', 'qwen/qwen3.6-plus')),
            'fallback_model' => env('LLM_VISION_FALLBACK_MODEL'),
            'connect_timeout' => (int) env('LLM_VISION_CONNECT_TIMEOUT', 10),
            'request_timeout' => (int) env('LLM_VISION_REQUEST_TIMEOUT', env('VISION_CALL_TIMEOUT', 45)),
        ],
        'embedding' => [
            'model' => env('LLM_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
            // Le modèle de secours doit produire la même dimension vectorielle.
            'fallback_model' => env('LLM_EMBEDDING_FALLBACK_MODEL'),
            'connect_timeout' => null,
            'request_timeout' => 60,
        ],

        // ============================================================
        // MCP / AGENTS
        // ============================================================

        'mcp' => [
            'model' => env('LLM_MCP_MODEL', env('MCP_LLM_MODEL', 'openai/gpt-4.1-mini')),
            'fallback_model' => env('LLM_MCP_FALLBACK_MODEL', env('MCP_LLM_FALLBACK_MODEL', 'deepseek/deepseek-v3.2')),
            'connect_timeout' => null,
            'request_timeout' => 45,
        ],

        // ============================================================
        // AI ENGAGEMENT
        // ============================================================

        'proactive_decision' => [
            'model' => env('LLM_PROACTIVE_MODEL', env('PROACTIVE_DECISION_MODEL', 'openai/gpt-4.1-mini')),
            'fallback_model' => env('LLM_PROACTIVE_FALLBACK_MODEL', 'deepseek/deepseek-v3.2'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        // ============================================================
        // PROSPECTION
        // ============================================================

        'prospecting_web_search' => [
            'model' => env('LLM_PROSPECTING_MODEL', env('PROSPECTING_WEB_SEARCH_MODEL', 'deepseek/deepseek-v3.2')),
            'fallback_model' => env('LLM_PROSPECTING_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => null,
            'request_timeout' => null,
        ],

        // ============================================================
        // CRAWL / INDEXATION
        // ============================================================

        'crawl' => [
            'model' => env('LLM_CRAWL_MODEL', 'deepseek/deepseek-v3.2'),
            'fallback_model' => env('LLM_CRAWL_FALLBACK_MODEL', 'openai/gpt-4.1-mini'),
            'connect_timeout' => 10,
            'request_timeout' => 30,
        ],
    ],
];
