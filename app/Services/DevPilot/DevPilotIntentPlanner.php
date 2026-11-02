<?php

namespace App\Services\DevPilot;

use App\Domain\MCP\Orchestration\OpenRouterToolClient;
use RuntimeException;
use Throwable;

/**
 * Tool-free language-model call that turns a free-form request into a routing
 * plan and a typed task contract. It can narrow, never authorize, access: every
 * identifier it returns is validated against the server-owned registry, and a
 * rejected answer is sent back to the model once with the exact validation
 * errors so a small formatting slip never degrades into "expose every tool".
 */
final class DevPilotIntentPlanner
{
    public const CONTRACT_VERSION = 1;

    private const PLAN_KEYS = ['mode', 'domains', 'needs', 'completion_criteria', 'max_inspection_calls', 'answer'];

    private const OUTPUT_FLAGS = [
        'list_content_types', 'check_content_type_accessibility', 'list_posts',
        'article_summary', 'article_headings', 'plugin_dependencies',
    ];

    public function __construct(
        private readonly OpenRouterToolClient $llm,
        private readonly DevPilotToolRegistry $registry,
    ) {}

    /**
     * @param list<array{role:string,content:string}> $dialogue
     * @param list<string> $availableDomains
     * @param list<string> $availableNeeds
     * @return array{plan:?array,response:array,responses:list<array>,plugin_intent:?array,contract:array,attempts:int,errors:list<string>}
     */
    public function plan(string $instruction, array $dialogue, array $availableDomains, array $availableNeeds): array
    {
        $input = [
            'objective' => mb_strcut($instruction, 0, 6000),
            'recent_dialogue' => array_slice(array_map(static fn (array $message): array => [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => mb_strcut((string) $message['content'], 0, 1200),
            ], $dialogue), -8),
            'domains_available_to_this_connection' => $this->registry->domainDescriptors($availableDomains),
            'registered_evidence_needs' => $this->registry->needDescriptors($availableDomains, $availableNeeds),
        ];
        $messages = [
            ['role' => 'system', 'content' => $this->routerPrompt()],
            ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
        ];

        $maxAttempts = 1 + max(0, min(2, (int) config('devpilot.tool_router.repair_attempts', 1)));
        $responses = [];
        $decoded = null;
        $plan = null;
        $errors = [];
        $lastException = null;
        $attempts = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attempts = $attempt;
            try {
                $response = $this->send($messages, 900);
            } catch (Throwable $exception) {
                // Transient provider failure: retry once with the same messages.
                $lastException = $exception;
                continue;
            }
            $responses[] = $response;
            $text = $response['text'] ?? ($response['raw_message']['content'] ?? null);
            $candidate = is_string($text) && trim($text) !== '' ? json_decode(trim($text), true) : null;

            if (! is_array($candidate) || json_last_error() !== JSON_ERROR_NONE) {
                $decoded = null;
                $errors = ['The previous reply was not a single valid JSON object.'];
            } else {
                $decoded = $candidate;
                $validation = $this->registry->validatePlanDetailed(
                    array_intersect_key($decoded, array_flip(self::PLAN_KEYS)),
                    $availableDomains,
                    $availableNeeds,
                );
                $plan = $validation['plan'];
                $errors = $validation['errors'];
                if ($plan !== null) break;
            }

            if ($attempt < $maxAttempts) {
                $messages[] = ['role' => 'assistant', 'content' => is_string($text) ? mb_strcut(trim($text), 0, 2000) : '{}'];
                $messages[] = ['role' => 'user', 'content' => json_encode([
                    'validation_errors' => array_slice($errors, 0, 8),
                    'instruction' => 'The server rejected the previous JSON. Return one corrected JSON object only, with the same schema, using only domain and evidence-need ids from the supplied lists.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
            }
        }

        if ($responses === []) throw $lastException ?? new RuntimeException('Intent planner returned no JSON content.');
        if ($decoded === null) throw new RuntimeException('Intent planner returned invalid JSON.');

        return [
            'plan' => $plan,
            'response' => $responses[array_key_last($responses)],
            'responses' => $responses,
            'plugin_intent' => $this->validatedPluginIntent($decoded['plugin_intent'] ?? null),
            'contract' => $this->validatedContract($decoded['contract'] ?? null),
            'attempts' => $attempts,
            'errors' => $plan === null ? $errors : [],
        ];
    }

    /** @return array{version:int,language:?string,output:array<string,bool>,targets:array{plugins:list<string>,content:?array}} */
    public static function emptyContract(): array
    {
        return [
            'version' => self::CONTRACT_VERSION,
            'language' => null,
            'output' => array_fill_keys(self::OUTPUT_FLAGS, false),
            'targets' => ['plugins' => [], 'content' => null],
        ];
    }

    /**
     * Normalise the model's task contract. Never throws: anything missing or
     * malformed simply means "not requested", and only a literal JSON true can
     * switch an output flag on.
     */
    public function validatedContract(mixed $raw): array
    {
        $contract = self::emptyContract();
        if (! is_array($raw)) return $contract;

        $language = is_string($raw['language'] ?? null) ? strtolower(trim($raw['language'])) : '';
        if (preg_match('/^([a-z]{2,3})(?:[-_][a-z0-9]{2,8})?$/D', $language, $matches) === 1) $contract['language'] = $matches[1];

        $output = is_array($raw['output'] ?? null) ? $raw['output'] : [];
        foreach (self::OUTPUT_FLAGS as $flag) $contract['output'][$flag] = ($output[$flag] ?? false) === true;
        if ($contract['output']['check_content_type_accessibility']) $contract['output']['list_content_types'] = true;

        $targets = is_array($raw['targets'] ?? null) ? $raw['targets'] : [];
        $plugins = [];
        foreach (is_array($targets['plugins'] ?? null) && array_is_list($targets['plugins']) ? array_slice($targets['plugins'], 0, 20) : [] as $mention) {
            if (! is_string($mention)) continue;
            $mention = trim(preg_replace('/\s+/u', ' ', $mention) ?? $mention);
            if ($mention !== '' && mb_strlen($mention) <= 120) $plugins[] = $mention;
        }
        $contract['targets']['plugins'] = array_values(array_unique($plugins));

        $content = is_array($targets['content'] ?? null) ? $targets['content'] : null;
        if ($content !== null) {
            $id = $content['id'] ?? null;
            $id = is_int($id) ? $id : (is_string($id) && preg_match('/^\d{1,10}$/D', $id) === 1 ? (int) $id : null);
            $slug = is_string($content['slug'] ?? null) ? strtolower(trim($content['slug'])) : null;
            if ($slug !== null && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) $slug = null;
            $title = is_string($content['title'] ?? null) ? mb_strcut(trim($content['title']), 0, 200) : null;
            $resolved = ['id' => $id !== null && $id > 0 ? $id : null, 'slug' => $slug, 'title' => $title !== '' ? $title : null];
            if (array_filter($resolved, static fn ($value): bool => $value !== null) !== []) $contract['targets']['content'] = $resolved;
        }
        // A request aimed at one specific record is never a request to enumerate posts.
        if ($contract['targets']['content'] !== null) $contract['output']['list_posts'] = false;

        return $contract;
    }

    /** Validate the model's narrow plugin-update classification before server use. */
    public function validatedPluginIntent(mixed $raw): ?array
    {
        if (! is_array($raw) || ($raw['action'] ?? null) !== 'update'
            || ! in_array($raw['scope'] ?? null, ['all_available', 'named_targets'], true)
            || ! is_array($raw['targets'] ?? null) || ! array_is_list($raw['targets'])
            || count($raw['targets']) > 20) return null;

        $targets = [];
        foreach ($raw['targets'] as $target) {
            if (! is_string($target)) return null;
            $target = trim(preg_replace('/\s+/u', ' ', $target) ?? $target);
            if ($target === '' || mb_strlen($target) > 120) return null;
            $targets[] = $target;
        }
        $targets = array_values(array_unique($targets));
        if (($raw['scope'] === 'all_available' && $targets !== [])
            || ($raw['scope'] === 'named_targets' && $targets === [])) return null;

        return ['action' => 'update', 'scope' => $raw['scope'], 'targets' => $targets];
    }

    /**
     * Map user-facing plugin names to slugs of the signed inventory. The model
     * only matches; the server accepts a slug only if it exists in the inventory.
     *
     * @return array{slugs:list<string>,response:array}
     */
    public function resolvePluginTargets(array $targetNames, array $inventoryRecords, ?string $instruction = null): array
    {
        $targets = array_values(array_filter($targetNames, static fn ($target): bool => is_string($target) && trim($target) !== ''));
        $candidates = [];
        foreach (array_slice($inventoryRecords, 0, 100) as $record) {
            if (! is_array($record) || ! is_string($record['slug'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $record['slug']) !== 1) continue;
            $candidates[] = [
                'slug' => strtolower($record['slug']),
                'name' => is_string($record['name'] ?? null) ? mb_strcut($record['name'], 0, 160) : '',
            ];
        }
        if ($targets === [] || $candidates === []) return ['slugs' => [], 'response' => []];

        $payload = [
            'user_mentions' => $targets,
            'installed_extensions_from_signed_inventory' => $candidates,
        ];
        if ($instruction !== null) $payload['user_request'] = mb_strcut($instruction, 0, 1500);

        $response = $this->send([
            ['role' => 'system', 'content' => <<<'PROMPT'
Match each user-provided extension mention to one installed WordPress extension from the supplied signed inventory. A mention may be a display name, a partial or misspelled name, a brand name, a slug, or a description of what the extension does; use the extension names and slugs, and the user request when supplied, to decide. This is name resolution only: do not select extensions the user did not refer to. Use only candidate slugs exactly as supplied. If no candidate clearly matches, or several are equally plausible, return null for that mention. Return one JSON object only: {"matches":[{"mention":"exact input mention","slug":"candidate slug or null"}]}. Include exactly one mapping for every input mention.
PROMPT],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
        ], 600);

        $text = $response['text'] ?? ($response['raw_message']['content'] ?? null);
        $decoded = is_string($text) ? json_decode(trim($text), true) : null;
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded['matches'] ?? null)) return ['slugs' => [], 'response' => $response];

        $validMentions = array_fill_keys(array_map(static fn (string $target): string => mb_strtolower(trim($target)), $targets), true);
        $validSlugs = array_fill_keys(array_column($candidates, 'slug'), true);
        $resolved = [];
        foreach ($decoded['matches'] as $match) {
            if (! is_array($match) || ! is_string($match['mention'] ?? null)) return ['slugs' => [], 'response' => $response];
            $mention = mb_strtolower(trim($match['mention']));
            $slug = $match['slug'] ?? null;
            if (! isset($validMentions[$mention]) || ! is_string($slug) || ! isset($validSlugs[strtolower($slug)])) return ['slugs' => [], 'response' => $response];
            $resolved[$mention] = strtolower($slug);
        }
        if (count($resolved) !== count($validMentions)) return ['slugs' => [], 'response' => $response];

        return ['slugs' => array_values(array_unique($resolved)), 'response' => $response];
    }

    /** @param list<array{role:string,content:string}> $messages */
    private function send(array $messages, int $maxTokens): array
    {
        return $this->llm->send(
            $messages,
            [],
            'none',
            temperature: 0.0,
            maxTokens: $maxTokens,
            requestTimeoutSeconds: min(30, max(5, (int) config('devpilot.tool_router.timeout_seconds', 20))),
            extraBody: [
                'response_format' => ['type' => 'json_object'],
                'provider' => ['data_collection' => config('devpilot.llm_data_collection', 'deny')],
            ],
            modelOverride: (string) config('devpilot.tool_router.model', 'openai/gpt-4.1-mini'),
        );
    }

    private function routerPrompt(): string
    {
        return <<<'PROMPT'
You route requests for a remote project agent. Return exactly one JSON object and no markdown. If the user only makes conversation or asks a general question that needs no private project data, tool, remote connection, or action, answer them directly in the answer field and select mode "answer". Never use answer mode for a request that depends on their tenant, connected service, project files, site state, or a requested change.

Understand the objective the way a capable human assistant would. The user writes in natural language, in any language, and does not know identifiers: never expect slugs, ids or tool names in the request. Use the whole recent dialogue to resolve references such as "it", "that page" or "the same plugin".

ROUTING
- The evidence needs listed in registered_evidence_needs are the only vocabulary. Each need is a proof the server will enforce and the only thing that exposes tools, so choose the smallest set that is sufficient for the whole objective: an unneeded need wastes tokens and exposes unrelated data, a missing one blocks the task.
- A multi-part request needs every part covered, across domains when necessary. Do not add a domain for a part the user did not ask about. When the request is truly ambiguous between two readings, cover both; do not select everything "just in case".
- Read each need's description: some needs already include what another would otherwise provide, and some must only be chosen when the user explicitly asks for them.
- A *.proposed need prepares a change for human approval. Select one only when the user asks for a change. mode is read_only when the user only wants observation, modify when a change is requested in one domain, mixed when observation and change are both requested or changes span domains. Preserve explicit constraints such as "do not modify anything": that means read_only, and a read-only inventory request is never an instruction to change anything.
- Use mode "answer" only for a self-contained general answer or conversational response. It must have empty domains and needs, max_inspection_calls 0, and a concise user-facing answer. If any external fact or action is needed, use the registered tool needs instead.
- Never invent domains, needs, tools, permissions or identifiers. The server validates your output and still enforces permissions and approval.
- max_inspection_calls: 2 for a single lookup, 3 to 6 for focused tasks, up to 12 for multi-step work.
- For a requested change to a page, view, component, or visible selector, if the user supplied a URL, treat it as the target and do not ask for its URL or file path again. Resolve the URL pathname through the project's route/router configuration first, follow the route to its handler/controller and rendered template/view/component, then read that source file. If several URLs are supplied (for example localized URLs), trace each and check whether they share the same source. Search for the selector only after narrowing to the routed source area; a selector match across unrelated views is not proof of ambiguity. File names and search excerpts are leads only, never enough to stop or propose an edit.
- completion_criteria is a short explanation only; the server-owned needs control completion.

PLUGIN UPDATES
Always include plugin_intent. For a requested update of installed plugins, use {"action":"update","scope":"all_available","targets":[]} when the user means every plugin that has an update, and {"action":"update","scope":"named_targets","targets":["name exactly as the user wrote it"]} for named plugins. Targets are the user's own words (display name, brand, slug or description); never manufacture slugs, the server resolves them against the signed inventory. Use null for read-only checks and for anything that is not an update.

CONTRACT
Always include contract. It records what the user wants to receive, after applying every negation and constraint in the request ("do not list the content types" means false):
{"language":"primary language subtag of the user's message, e.g. fr, en, es","output":{"list_content_types":bool,"check_content_type_accessibility":bool,"list_posts":bool,"article_summary":bool,"article_headings":bool,"plugin_dependencies":bool},"targets":{"plugins":["extension names exactly as the user wrote them, for any action"],"content":null|{"id":int|null,"slug":string|null,"title":string|null}}}
- list_content_types: the user wants the site's content types listed. check_content_type_accessibility: the user wants to know whether those types are reachable. list_posts: the user explicitly wants several published posts enumerated; it is false whenever the request is about one specific article or page (read, summarize, find, change it). article_summary: a summary of one article or page. article_headings: the H2/H3 outline of one article or page. plugin_dependencies: the user wants plugin dependency information.
- targets.content: the single article, page or record the request is about. Set id or slug only when the user stated them explicitly; otherwise keep them null and put the words that identify it in title (for example "mentions légales"). Use null when no single record is targeted. A user's "article" may be a page on the site: do not assume the content type.

SCHEMA
{"mode":"answer|read_only|modify|mixed","domains":["domain id"],"needs":["need id"],"completion_criteria":"short sentence","max_inspection_calls":3,"answer":null|"concise user-facing response","plugin_intent":null|{"action":"update","scope":"all_available|named_targets","targets":["..."]},"contract":{...}}
Every need's domain must also be listed in domains.

EXAMPLES (illustrative; use only ids present in registered_evidence_needs)
"Bonjour, comment vas-tu ?" -> answer, domains [], needs [], max_inspection_calls 0, answer in French.
"Explique-moi simplement ce qu’est WordPress." -> answer, domains [], needs [], max_inspection_calls 0, answer with a general explanation; do not inspect the tenant's site.
"Liste les extensions installées avec leur version et leur état. Ne modifie rien." -> read_only, domains ["wp.plugins"], needs ["wp.plugins.inventory.complete"], all output flags false, plugin_intent null.
"Which content types does the site expose, and list my published posts" -> read_only, domains ["wp.content"], needs ["wp.content.types.complete","wp.content.list.complete"], list_content_types true, list_posts true.
"Résume l'article sur nos mentions légales" -> read_only, domains ["wp.content"], needs ["wp.content.lookup.complete","wp.content.read.complete"], article_summary true, list_posts false, list_content_types false, targets.content {"id":null,"slug":null,"title":"mentions légales"}.
"Active Contact Form 7" -> modify, domains ["wp.plugins"], needs ["wp.plugins.inventory.complete","wp.plugins.activate.proposed"], targets.plugins ["Contact Form 7"].
"Mets à jour tout ce qui a une mise à jour" -> modify, domains ["wp.plugins"], needs ["wp.plugins.update.proposed"], plugin_intent {"action":"update","scope":"all_available","targets":[]}.
"Résume l'article 128 et donne ses titres H2" -> read_only, domains ["wp.content"], needs ["wp.content.read.complete"], article_summary true, article_headings true, targets.content {"id":128,"slug":null,"title":null}.
"Quelles extensions sont actives, et crée un brouillon intitulé Bienvenue" -> mixed, domains ["wp.plugins","wp.content"], needs ["wp.plugins.inventory.complete","wp.content.create.proposed"].
PROMPT;
    }
}
