<?php

namespace App\SocialIntelligence\Services;

class SocialTopicExtractor
{
    private array $stopWords = ['avec','dans','pour','plus','sans','les','des','une','sur','est','que','qui','aux','par','son','ses','notre','votre','nous','vous','this','that','with','from','your','about','and','the','for','are','not','our'];

    public function extract(array $records, int $limit = 20): array
    {
        $topics = [];
        foreach ($records as $record) {
            $text = mb_strtolower((string) ($record['text'] ?? $record['body'] ?? ''));
            preg_match_all('/#[\p{L}\p{N}_-]{2,}/u', $text, $hashtags);
            $tokens = array_merge($hashtags[0] ?? [], preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $tokens = array_values(array_unique(array_map(static fn ($token) => trim((string) $token, '#'), $tokens)));
            $tokens = array_values(array_filter($tokens, fn ($token) => mb_strlen($token) >= 3 && !in_array($token, $this->stopWords, true) && !is_numeric($token)));
            foreach ($tokens as $token) {
                $key = $token;
                $provider = $record['provider'] ?? null;
                $sourceType = $record['source_type'] ?? 'content';
                $index = "{$sourceType}|{$provider}|{$key}";
                $topics[$index] ??= ['topic_key' => $key, 'label' => ucfirst($key), 'provider' => $provider, 'source_type' => $sourceType, 'frequency' => 0, 'interaction_count' => 0];
                $topics[$index]['frequency']++;
                $topics[$index]['interaction_count'] += (int) ($record['interaction_count'] ?? 0);
            }
        }
        return collect($topics)->sortByDesc(fn ($topic) => [$topic['frequency'], $topic['interaction_count']])->take($limit)->values()->all();
    }
}
