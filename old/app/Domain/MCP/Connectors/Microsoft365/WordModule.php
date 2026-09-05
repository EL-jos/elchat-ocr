<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

final class WordModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'word'; }

    public function label(): string { return 'Word'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/e/e8/Microsoft_Office_Word_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('word_get_document', 'Récupère les métadonnées d’un document Word présent dans OneDrive ou SharePoint.', ['item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'word.read'),
            $this->writeTool('word_create_document', 'Crée un vrai document Word .docx à partir d’un titre et d’un contenu, puis le dépose dans OneDrive ou SharePoint.', ['name' => ['type' => 'string'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'parent_item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['name', 'content'], 'word.create_document', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return ['word_get_document' => ['Files.Read'], 'word_create_document' => ['Files.ReadWrite']];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'word_get_document' => $this->getDocument($graph, $params),
            'word_create_document' => $this->createDocument($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Word Microsoft 365."),
        };
    }

    private function getDocument(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $file = $g->get($this->itemPath($p), ['$select' => 'id,name,size,file,webUrl,lastModifiedDateTime,createdDateTime,parentReference,eTag']);
        if (!str_ends_with(strtolower((string) ($file['name'] ?? '')), '.docx')) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas un document Word .docx.');
        }
        return ToolResult::ok(['document' => $this->fileSummary($file)], 'Document Word récupéré.');
    }

    private function createDocument(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $name = $this->safeFilename((string) $p['name'], '.docx');
        $temporaryPath = tempnam(sys_get_temp_dir(), 'elchat-word-');
        if ($temporaryPath === false) {
            return ToolResult::fail('document_generation_failed', 'Le document Word temporaire n’a pas pu être préparé.');
        }

        try {
            $word = new PhpWord();
            $section = $word->addSection();
            if (!empty($p['title'])) {
                $section->addTitle((string) $p['title'], 1);
            }
            foreach (preg_split('/\R/u', (string) $p['content']) ?: [] as $paragraph) {
                $section->addText($paragraph);
            }

            IOFactory::createWriter($word, 'Word2007')->save($temporaryPath);
            $content = file_get_contents($temporaryPath);
            if ($content === false) {
                return ToolResult::fail('document_generation_failed', 'Le document Word n’a pas pu être lu après sa génération.');
            }
        } catch (\Throwable $exception) {
            return ToolResult::fail('document_generation_failed', 'Le document Word n’a pas pu être créé.');
        } finally {
            @unlink($temporaryPath);
        }

        // Keep Graph exceptions outside the document-generation guard so the
        // connector can classify 401/403/404/429/5xx consistently.
        try {
            $file = $this->uploadBytes($g, $p, $name, $content, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ToolResult::fail('upload_failed', $exception->getMessage());
        }
        return ToolResult::ok(['document' => $this->fileSummary($file)], 'Document Word créé dans Microsoft 365.');
    }
}
