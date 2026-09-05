<?php

namespace App\Mappers;

class ProductMapper
{
    public static function map(array $rows, array $mapping): array
    {
        return array_map(function ($row) use ($mapping) {
            $product = [];

            foreach ($mapping as $standardKey => $userColumn) {
                if (!$userColumn) continue;

                $product[$standardKey] = $row[$userColumn] ?? null;
            }

            return $product;
        }, $rows);
    }
}
