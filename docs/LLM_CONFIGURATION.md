# Configuration centralisée des LLM

La source de vérité des modèles ELChat est [`config/llm.php`](../config/llm.php).
Chaque tâche possède une paire clé–valeur :

```php
'chat' => [
    'model' => 'openai/gpt-4.1-mini',
    'fallback_model' => 'deepseek/deepseek-chat-v3.1',
],
```

Les délais suivent la même centralisation, avec deux valeurs distinctes :

```php
'provider' => [
    'connect_timeout' => 10,  // ouverture de la connexion
    'request_timeout' => 120, // durée totale de la requête
],

'tasks' => [
    'chat' => [
        'connect_timeout' => null, // hérite du global
        'request_timeout' => null, // hérite du global
    ],
    'chat_summary' => [
        'connect_timeout' => null,
        'request_timeout' => 30, // surcharge propre à la tâche
    ],
],
```

La priorité est : surcharge passée à l’appel, puis valeur de la tâche, puis
valeur globale. `timeout` reste accepté dans les options d’un appel comme
alias historique de `request_timeout`.

Pour changer le modèle du chat, de la vision, du RAG ou d’une autre tâche,
modifier la valeur `model` de la tâche concernée dans ce fichier. Le modèle de
secours est essayé automatiquement si le modèle principal est indisponible.
Les modèles sans endpoint et les erreurs HTTP `4xx` déterministes sont détectés
immédiatement ; le modèle de secours est alors essayé sans consommer les
tentatives du modèle principal. Les erreurs `429`, `5xx`, les timeouts et les
erreurs réseau transitoires utilisent un backoff exponentiel borné avec jitter.
Le délai `Retry-After` du provider est respecté lorsqu’il est fourni.

Les paramètres de production sont configurables dans `llm.provider` ou via
les variables suivantes :

```dotenv
LLM_MAX_RETRIES=3
LLM_RETRY_BASE_DELAY_MS=200
LLM_RETRY_MAX_DELAY_MS=5000
LLM_RETRY_JITTER_PERCENT=20
LLM_MULTI_HOP_MAX_HOPS=2
```

Le plafond protège les workers contre un `Retry-After` excessivement long.
Le retrieval multi-hop est limité à deux hops par défaut afin de contenir le
temps de réponse et le coût. Le plafond est commun aux deux implémentations
RAG (`MultiHopPipelineService` et `MultiHopPipelineServiceV2`) et reste
configurable via `LLM_MULTI_HOP_MAX_HOPS`.

Tâches disponibles : `chat`, `chat_summary`, `social_lead_rewrite`, `follow_up_detection`,
`conversation_rewrite`, `retrieval_query_expansion`, `query_analysis`, les
tâches `multi_hop_*`, les tâches `answer_*`, les tâches `rag_*`, `vision`,
`embedding`, `mcp`, `proactive_decision`, `prospecting_web_search` et `crawl`.

Les tâches `embedding` et `rag_rerank` acceptent également un
`fallback_model`, mais celui-ci est vide par défaut : un modèle d’embedding doit
conserver la même dimension vectorielle et un modèle de reranking doit rester
compatible avec l’endpoint utilisé.

Les variables d’environnement historiques et les variables `LLM_*` restent
acceptées comme surcharges pour les déploiements existants. Après une
modification en environnement avec configuration Laravel mise en cache :

```bash
php artisan config:clear
```

Les appels ponctuels qui transmettent encore `model` ou `fallback_model` dans
les options restent compatibles ; les appels applicatifs utilisent désormais
une `task` du registre central. `LLMService` centralise également les appels
multimodaux de vision, les embeddings et le reranking. Le client MCP de
function-calling reste volontairement séparé.
