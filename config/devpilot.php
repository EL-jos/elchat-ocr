<?php

return [
    'queue' => env('DEVPILOT_QUEUE', 'devpilot'),
    'tool_router' => [
        // When disabled, expose the full permission-filtered catalogue; planner failures fail closed.
        'enabled' => (bool) env('DEVPILOT_TOOL_ROUTER', true),
        'model' => env('DEVPILOT_TOOL_ROUTER_MODEL', 'openai/gpt-4.1-mini'),
        'timeout_seconds' => max(5, min(30, (int) env('DEVPILOT_TOOL_ROUTER_TIMEOUT_SECONDS', 20))),
        // Extra planner calls (0-2) that send server validation errors back to the model before any fallback.
        'repair_attempts' => max(0, min(2, (int) env('DEVPILOT_TOOL_ROUTER_REPAIR_ATTEMPTS', 1))),
        'max_domain_requests' => max(0, min(2, (int) env('DEVPILOT_TOOL_ROUTER_MAX_DOMAIN_REQUESTS', 2))),
        'max_inspection_calls' => max(2, min(12, (int) env('DEVPILOT_TOOL_ROUTER_MAX_INSPECTION_CALLS', 8))),
        'history_items' => max(1, min(3, (int) env('DEVPILOT_TOOL_ROUTER_HISTORY_ITEMS', 3))),
    ],
    // Private targets are denied unless explicitly permitted here and enabled on the connection.
    'private_network_allowed_cidrs' => array_values(array_filter(array_map(
        static fn (string $cidr): string => trim($cidr),
        explode(',', (string) env('DEVPILOT_PRIVATE_NETWORK_ALLOWED_CIDRS', '')),
    ))),
    'llm_request_timeout' => max(1, (int) env('DEVPILOT_LLM_REQUEST_TIMEOUT', 180)),
    'max_run_seconds' => max(60, (int) env('DEVPILOT_MAX_RUN_SECONDS', 1800)),
    'command_max_seconds' => max(1, (int) env('DEVPILOT_COMMAND_MAX_SECONDS', 1800)),
    'command_idle_seconds' => max(1, (int) env('DEVPILOT_COMMAND_IDLE_SECONDS', 600)),
    'task_token_budget' => max(1, (int) env('DEVPILOT_TASK_TOKEN_BUDGET', 200000)),
    'daily_token_budget_per_site' => max(1, (int) env('DEVPILOT_DAILY_TOKEN_BUDGET_PER_SITE', 1000000)),
    'concurrent_tasks_per_site' => max(1, (int) env('DEVPILOT_CONCURRENT_TASKS_PER_SITE', 3)),
    'watchdog_stale_minutes' => max(90, (int) env('DEVPILOT_WATCHDOG_STALE_MINUTES', 90)),
    'proposal_expiration_hours' => max(1, (int) env('DEVPILOT_PROPOSAL_EXPIRATION_HOURS', 24)),
    'apply_queue' => env('DEVPILOT_APPLY_QUEUE', 'devpilot-apply'),
    'llm_data_collection' => in_array((string) env('DEVPILOT_LLM_DATA_COLLECTION', 'deny'), ['deny', 'allow'], true)
        ? (string) env('DEVPILOT_LLM_DATA_COLLECTION', 'deny') : 'deny',
    'task_retention_days' => (int) env('DEVPILOT_TASK_RETENTION_DAYS', 180),
    'command_output_limit_bytes' => max(1024, (int) env('DEVPILOT_COMMAND_OUTPUT_LIMIT_BYTES', 65536)),
    'wordpress_response_limit_bytes' => max(1024, min(10 * 1024 * 1024, (int) env('DEVPILOT_WORDPRESS_RESPONSE_LIMIT_BYTES', 4194304))),
    'wordpress_connect_timeout_seconds' => max(1, min(5, (int) env('DEVPILOT_WORDPRESS_CONNECT_TIMEOUT_SECONDS', 3))),
    'wordpress_timeout_seconds' => max(1, min(15, (int) env('DEVPILOT_WORDPRESS_TIMEOUT_SECONDS', 10))),
    'wordpress_bridge' => [
        // A worker polls briefly, then yields the queue slot and resumes the same idempotent operation.
        'operation_poll_window_seconds' => max(1, min(60, (int) env('DEVPILOT_WP_OPERATION_POLL_WINDOW_SECONDS', 12))),
        'operation_poll_max_cycles' => max(1, min(100, (int) env('DEVPILOT_WP_OPERATION_POLL_MAX_CYCLES', 24))),
        'operation_poll_retry_delay_seconds' => max(2, min(60, (int) env('DEVPILOT_WP_OPERATION_POLL_RETRY_DELAY_SECONDS', 8))),
    ],
    // Only these server-owned diagnostics may run --version / -v without approval.
    'diagnostic_version_binaries' => array_values(array_filter(array_map(
        static fn (string $binary): string => trim($binary),
        explode(',', (string) env('DEVPILOT_DIAGNOSTIC_VERSION_BINARIES', 'php,composer,node,npm,python,python3,java,git')),
    ))),
];
