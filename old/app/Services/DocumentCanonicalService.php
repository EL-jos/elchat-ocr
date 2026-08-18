<?php

namespace App\Services;

use App\Models\Document;
use App\Traits\TextNormalizer;
use Illuminate\Support\Facades\Log;

class DocumentCanonicalService
{
    use TextNormalizer;
    /*public function buildCanonicalDocument(string $path, string $extension, string $fullPath): array
    {
        return match ($extension) {

            'pdf' => $this->buildPdfCanonical($fullPath),

            'doc', 'docx' => $this->buildDocxCanonical($fullPath),

            'xls', 'xlsx' => $this->buildExcelCanonical($fullPath),

            'csv' => $this->buildCsvCanonical($fullPath),

            'txt' => [
                'type' => 'txt',
                'title' => basename($fullPath),
                'blocks' => [],
                'raw_text' => $this->normalizeText(file_get_contents($fullPath)),
            ],

            default => [
                'type' => 'unknown',
                'title' => basename($fullPath),
                'blocks' => [],
                'raw_text' => '',
            ]
        };
    }*/
    public function buildCanonicalDocument(string $path, string $extension, string $fullPath): array
    {
        return [
            'type' => 'document',
            'format' => $extension,
            'title' => basename($fullPath),
            'blocks' => $this->extractBlocks($fullPath, $extension),
            'raw_text' => '',
        ];
    }
    private function extractBlocks(string $fullPath, string $extension): array
    {
        return match ($extension) {

            'pdf' => $this->buildPdfCanonical($fullPath),

            'doc', 'docx' => $this->buildDocxCanonical($fullPath),

            'xls', 'xlsx' => $this->buildExcelCanonical($fullPath),

            'csv' => $this->buildCsvCanonical($fullPath),

            'txt' => [
                [
                    'type' => 'block',
                    'subtype' => 'paragraph',
                    'text' => $this->normalizeText(file_get_contents($fullPath)),
                    'meta' => []
                ]
            ],

            default => []
        };
    }
    public function buildPdfCanonical(string $fullPath): array
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($fullPath);

        $text = $this->normalizePdfText($pdf->getText());

        return [
            'type' => 'pdf',
            'title' => basename($fullPath),
            'blocks' => [
                [
                    'type' => 'block',
                    'subtype' => 'paragraph',
                    'text' => $text,
                    'meta' => [
                        'source' => 'pdf'
                    ]
                ]
            ],
            'raw_text' => $text,
        ];
    }
    public function buildExcelCanonical(string $fullPath): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);

        $blocks = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {

            $rows = $sheet->toArray(null, true, true, true);
            $headers = array_shift($rows) ?? [];

            foreach ($rows as $row) {

                if (count(array_filter($row)) === 0) continue;

                $line = [];

                foreach ($headers as $col => $header) {
                    $line[] = ($header ?? $col) . ': ' . ($row[$col] ?? '');
                }

                $blocks[] = [
                    'type' => 'block',
                    'subtype' => 'table',
                    'text' => json_encode([
                        'columns' => $headers,
                        'values' => $row
                    ], JSON_UNESCAPED_UNICODE),
                    'meta' => [
                        'sheet' => $sheet->getTitle()
                    ]
                ];
            }
        }

        $rawText = $this->normalizeText(
            implode("\n", array_map(function ($b) {
                return "[{$b['subtype']}] " . $b['text'];
            }, $blocks))
        );

        return [
            'type' => 'excel',
            'title' => basename($fullPath),
            'blocks' => $blocks,
            'raw_text' => $rawText,
        ];
    }
    public function buildCsvCanonical(string $fullPath): array
    {
        $delimiter = $this->detectCsvDelimiter($fullPath);

        $handle = fopen($fullPath, 'r');

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count(array_filter($data)) === 0) continue;
            $rows[] = $data;
        }

        fclose($handle);

        $headers = array_shift($rows) ?? [];

        $blocks = [];

        foreach ($rows as $row) {

            $line = [];

            foreach ($headers as $i => $header) {
                $line[] = ($header ?? $i) . ': ' . ($row[$i] ?? '');
            }

            $blocks[] = [
                'type' => 'block',
                'subtype' => 'table',
                'text' => json_encode([
                    'columns' => $headers,
                    'values' => $row
                ], JSON_UNESCAPED_UNICODE),
                'meta' => []
            ];
        }

        $rawText = $this->normalizeText(
            implode("\n", array_map(function ($b) {
                return "[{$b['subtype']}] " . $b['text'];
            }, $blocks))
        );

        return [
            'type' => 'csv',
            'title' => basename($fullPath),
            'blocks' => $blocks,
            'raw_text' => $rawText,
        ];
    }
    public function buildDocxCanonical(string $fullPath): array
    {
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);

            $blocks = [];
            $position = 0;

            foreach ($phpWord->getSections() as $section) {

                foreach ($section->getElements() as $element) {

                    $position++;

                    if (method_exists($element, 'getText')) {

                        $text = trim($element->getText());
                        if ($text === '') continue;

                        $blocks[] = [
                            'type' => 'block',
                            'subtype' => 'paragraph',
                            'text' => $this->normalizeText($text),
                            'meta' => [
                                'position' => $position
                            ]
                        ];
                    }

                    if ($element instanceof \PhpOffice\PhpWord\Element\Table) {

                        $tableContent = [];

                        foreach ($element->getRows() as $row) {

                            $rowData = [];

                            foreach ($row->getCells() as $cell) {

                                $cellText = '';

                                foreach ($cell->getElements() as $cellElement) {
                                    if (method_exists($cellElement, 'getText')) {
                                        $cellText .= $cellElement->getText() . ' ';
                                    }
                                }

                                $rowData[] = trim($cellText);
                            }

                            if (!empty(array_filter($rowData))) {
                                $tableContent[] = implode(' | ', $rowData);
                            }
                        }

                        if (!empty($tableContent)) {
                            $blocks[] = [
                                'type' => 'block',
                                'subtype' => 'table',
                                'text' => implode("\n", $tableContent),
                                'meta' => [
                                    'position' => $position
                                ]
                            ];
                        }
                    }
                }
            }

            $rawText = $this->normalizeText(
                implode("\n", array_map(function ($b) {
                    return "[{$b['subtype']}] " . $b['text'];
                }, $blocks))
            );

            return [
                'type' => 'docx',
                'title' => basename($fullPath),
                'blocks' => $blocks,
                'raw_text' => $rawText
            ];

        } catch (\Throwable $e) {

            Log::error("DOCX canonical build failed", [
                'file' => $fullPath,
                'error' => $e->getMessage()
            ]);

            return [
                'type' => 'docx',
                'title' => basename($fullPath),
                'blocks' => [],
                'raw_text' => ''
            ];
        }
    }
    public function normalizePdfText(string $text): string
    {
        // fix broken line breaks
        $text = preg_replace('/(?<!\n)\n(?!\n)/', ' ', $text);

        // normalize multiple spaces
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // fix column-like spacing (common in PDFs)
        $text = preg_replace('/ {2,}/', ' | ', $text);

        return trim($text);
    }
    public function detectCsvDelimiter(string $fullPath): string
    {
        $delimiters = [',', ';', "\t", '|'];

        $handle = fopen($fullPath, 'r');
        if (!$handle) return ',';

        $line = fgets($handle);
        fclose($handle);

        if (!$line) return ',';

        $scores = [];

        foreach ($delimiters as $delim) {
            $scores[$delim] = count(str_getcsv($line, $delim));
        }

        // pick delimiter with most columns
        return array_search(max($scores), $scores, true) ?: ',';
    }
}
