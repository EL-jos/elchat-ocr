<?php

namespace App\Domain\MCP\Contracts;

final readonly class ToolSchema
{
    public function __construct(
        public string $connectorSlug,
        public string $name,
        public string $description,
        public array $parameters,
        public bool $isWriteAction = false,

        // 🆕 Suggestions utilisées UNIQUEMENT pour pré-remplir mcp_permissions
        // lors de l'activation d'un connecteur (voir PermissionEngine::seedDefaultsIfMissing).
        // La source de vérité reste toujours la table, modifiable dans l'admin.
        public string $defaultActorScope = 'visitor',   // 'visitor' | 'admin'
        public string $defaultMode = 'confirm',          // posture prudente par défaut
        public ?string $defaultConfirmActor = 'admin',   // pertinent seulement si defaultMode === 'confirm'
    ) {
    }

    public function toOpenAIFormat(): array
    {
        $parameters = $this->parameters;

        // 🆕 PHP encode un tableau vide en JSON [] et non {} — or OpenAI exige
        // que 'properties' soit un objet, même vide. On force le typage objet.
        if (isset($parameters['properties']) && empty($parameters['properties'])) {
            $parameters['properties'] = new \stdClass();
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->qualifiedName(),
                'description' => $this->description,
                'parameters' => $parameters,
            ],
        ];
    }

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
