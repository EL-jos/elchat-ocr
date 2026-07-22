<?php

namespace App\Domain\MCP\Contracts;

/**
 * Représente un outil exposé au LLM, au format function-calling.
 * Immuable par design (readonly) : un schema ne se modifie pas après coup,
 * on en construit un nouveau.
 */
final readonly class ToolSchema
{
    public function __construct(
        public string $connectorSlug,
        public string $name,          // ex: 'get_order_status'
        public string $description,   // description claire pour le LLM
        public array $parameters,     // JSON Schema des paramètres
        public bool $isWriteAction = false, // true = a un effet de bord (création, suppression, paiement...)
    ) {
    }

    /**
     * Format attendu par le paramètre 'tools' de l'API OpenRouter/OpenAI
     * chat completions (celle déjà utilisée par ChatService::callLLM).
     */
    public function toOpenAIFormat(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->qualifiedName(),
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }

    /**
     * Nom qualifié envoyé au LLM : "connecteur__outil" pour éviter les
     * collisions entre connecteurs (ex: deux connecteurs avec un tool
     * "create_event").
     */
    public function qualifiedName(): string
    {
        return "{$this->connectorSlug}__{$this->name}";
    }

    public static function fromQualifiedName(string $qualified): array
    {
        [$connector, $tool] = array_pad(explode('__', $qualified, 2), 2, null);

        return ['connector' => $connector, 'tool' => $tool];
    }
}
