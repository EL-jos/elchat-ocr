<?php

namespace App\Traits;

trait TextNormalizer
{
    protected function normalizeText(string $text): string
    {
        // normalize unicode spaces
        $text = preg_replace('/\h+/u', ' ', $text);

        // normalize line breaks
        $text = preg_replace("/\r\n|\r/u", "\n", $text);

        // collapse excessive empty lines
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);

        // trim spaces around lines
        $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text);

        // remove invisible/control chars
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

        return trim($text);
    }
    protected function normalizeForLexicalSearch(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
