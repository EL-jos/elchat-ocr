<?php

namespace App\Services\VisitorIntelligence;

use Illuminate\Support\Facades\Log;

final class RrwebChunkCodec
{
    /** Keep this identical for storage and replay decoding. */
    public const JSON_MAX_DEPTH = 512;

    /**
     * @return array{0:string,1:string,2:int}
     */
    public static function encode(array $events): array
    {
        $json = json_encode(
            $events,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            self::JSON_MAX_DEPTH,
        );
        $compressed = gzencode($json, 6);
        if (!is_string($compressed)) {
            throw new \RuntimeException('Le chunk rrweb n’a pas pu être compressé.');
        }

        $payload = base64_encode($compressed);
        return [$payload, hash('sha256', $payload), strlen($compressed)];
    }

    public static function decode(string $payload, string $format): array
    {
        if ($format !== 'rrweb-json-gzip-base64') return [];
        $compressed = base64_decode($payload, true);
        if (!is_string($compressed)) return [];
        $json = gzdecode($compressed);
        if (!is_string($json)) return [];

        try {
            $events = json_decode($json, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Log::warning('Visitor Intelligence rrweb chunk decoding failed.', [
                'error' => $exception->getMessage(),
                'payload_bytes' => strlen($payload),
            ]);
            return [];
        }

        return is_array($events) ? $events : [];
    }
}
