<?php

namespace App\Mappers;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductFileParser
{
    public static function parse(Document $document): array
    {
        return match ($document->extension) {
            'csv' => self::parseCsv($document),
            'xls', 'xlsx' => self::parseExcel($document),
            default => [],
        };
    }

    /**
     * 📄 CSV
     */
    protected static function parseCsv(Document $document): array
    {
        $path = public_path($document->path);

        if (!file_exists($path)) {
            Log::error('CSV file not found', ['path' => $path]);
            return [];
        }

        $rows = [];

        try {
            if (($handle = fopen($path, 'r')) === false) {
                return [];
            }

            // 1️⃣ Headers
            $headers = fgetcsv($handle, 0, ',');
            if (!$headers) {
                fclose($handle);
                return [];
            }

            $headers = array_map('trim', $headers);

            // 2️⃣ Rows
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if (count($data) !== count($headers)) {
                    continue; // ligne invalide
                }

                $rows[] = array_combine($headers, $data);
            }

            fclose($handle);

        } catch (\Throwable $e) {
            Log::error('CSV parsing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $rows;
    }

    /**
     * 📊 Excel (xls / xlsx)
     */
    protected static function parseExcel(Document $document): array
    {
        $path = public_path($document->path);

        if (!file_exists($path)) {
            Log::error('Excel file not found', ['path' => $path]);
            return [];
        }

        $rows = [];

        try {
            $spreadsheet = IOFactory::load($path);

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetRows = $sheet->toArray(null, true, true, true);

                if (count($sheetRows) < 2) {
                    continue;
                }

                // 1️⃣ Headers (1ère ligne)
                $headers = array_map(
                    fn ($h) => trim((string) $h),
                    array_shift($sheetRows)
                );

                // 2️⃣ Rows
                foreach ($sheetRows as $row) {
                    $row = array_values($row);

                    if (count($row) !== count($headers)) {
                        continue;
                    }

                    $rows[] = array_combine($headers, $row);
                }
            }

        } catch (\Throwable $e) {
            Log::error('Excel parsing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $rows;
    }
}
