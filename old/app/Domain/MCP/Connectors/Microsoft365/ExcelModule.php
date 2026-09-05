<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class ExcelModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'excel'; }

    public function label(): string { return 'Excel'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/6/60/Microsoft_Office_Excel_(2025%E2%80%93present).svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->writeTool('excel_create_session', 'Crée une session workbook Microsoft Graph pour les opérations Excel cohérentes.', ['item_id' => ['type' => 'string'], 'persist_changes' => ['type' => 'boolean'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'excel.write', 'auto'),
            $this->readTool('excel_list_worksheets', 'Liste les feuilles d’un classeur Excel stocké dans Microsoft 365.', ['item_id' => ['type' => 'string'], 'session_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'excel.read'),
            $this->readTool('excel_get_range', 'Lit une plage d’un classeur Excel.', ['item_id' => ['type' => 'string'], 'worksheet' => ['type' => 'string'], 'address' => ['type' => 'string'], 'session_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'worksheet', 'address'], 'excel.read'),
            $this->readTool('excel_list_tables', 'Liste les tableaux structurés d’une feuille Excel.', ['item_id' => ['type' => 'string'], 'worksheet' => ['type' => 'string'], 'session_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'worksheet'], 'excel.read'),
            $this->readTool('excel_list_table_rows', 'Liste les lignes d’un tableau Excel.', ['item_id' => ['type' => 'string'], 'worksheet' => ['type' => 'string'], 'table' => ['type' => 'string'], 'session_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'worksheet', 'table'], 'excel.read'),
            $this->writeTool('excel_add_table_row', 'Ajoute une ligne à un tableau Excel.', ['item_id' => ['type' => 'string'], 'worksheet' => ['type' => 'string'], 'table' => ['type' => 'string'], 'values' => ['type' => 'array'], 'session_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'worksheet', 'table', 'values'], 'excel.write', 'confirm'),
            $this->writeTool('excel_update_range', 'Met à jour les valeurs d’une plage Excel.', ['item_id' => ['type' => 'string'], 'worksheet' => ['type' => 'string'], 'address' => ['type' => 'string'], 'values' => ['type' => 'array'], 'session_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'worksheet', 'address', 'values'], 'excel.write', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'excel_create_session' => ['Files.ReadWrite'],
            'excel_list_worksheets' => ['Files.Read'], 'excel_get_range' => ['Files.Read'], 'excel_list_tables' => ['Files.Read'], 'excel_list_table_rows' => ['Files.Read'],
            'excel_add_table_row' => ['Files.ReadWrite'], 'excel_update_range' => ['Files.ReadWrite'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'excel_create_session' => $this->createSession($graph, $params),
            'excel_list_worksheets' => $this->listWorksheets($graph, $params),
            'excel_get_range' => $this->getRange($graph, $params),
            'excel_list_tables' => $this->listTables($graph, $params),
            'excel_list_table_rows' => $this->listTableRows($graph, $params),
            'excel_add_table_row' => $this->addRow($graph, $params),
            'excel_update_range' => $this->updateRange($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Excel Microsoft 365."),
        };
    }

    private function createSession(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $session = $g->post($this->workbookPath($p) . '/createSession', ['persistChanges' => (bool) ($p['persist_changes'] ?? false)]);
        return ToolResult::ok(['session' => $session], 'Session Excel créée.');
    }

    private function listWorksheets(MicrosoftGraphClient $g, array $p): ToolResult
    {
        return ToolResult::ok(['worksheets' => $g->collectPages($this->workbookPath($p) . '/worksheets', [], $this->excelHeaders($p))], 'Feuilles Excel récupérées.');
    }

    private function getRange(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = $this->workbookPath($p) . "/worksheets('" . $this->odata($p['worksheet']) . "')/range(address='" . $this->odata($p['address']) . "')";
        return ToolResult::ok(['range' => $g->get($path, [], $this->excelHeaders($p))], 'Plage Excel lue.');
    }

    private function listTables(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = $this->workbookPath($p) . "/worksheets('" . $this->odata($p['worksheet']) . "')/tables";
        return ToolResult::ok(['tables' => $g->collectPages($path, [], $this->excelHeaders($p))], 'Tableaux Excel récupérés.');
    }

    private function listTableRows(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = $this->workbookPath($p) . "/worksheets('" . $this->odata($p['worksheet']) . "')/tables('" . $this->odata($p['table']) . "')/rows";
        return ToolResult::ok(['rows' => $g->collectPages($path, [], $this->excelHeaders($p))], 'Lignes Excel récupérées.');
    }

    private function addRow(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = $this->workbookPath($p) . "/worksheets('" . $this->odata($p['worksheet']) . "')/tables('" . $this->odata($p['table']) . "')/rows";
        return ToolResult::ok(['row' => $g->post($path, ['values' => [$p['values']]], [], $this->excelHeaders($p))], 'Ligne ajoutée au tableau Excel.');
    }

    private function updateRange(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = $this->workbookPath($p) . "/worksheets('" . $this->odata($p['worksheet']) . "')/range(address='" . $this->odata($p['address']) . "')";
        return ToolResult::ok(['range' => $g->patch($path, ['values' => $p['values']], [], $this->excelHeaders($p))], 'Plage Excel mise à jour.');
    }
}
