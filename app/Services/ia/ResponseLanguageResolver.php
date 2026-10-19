<?php

namespace App\Services\ia;

/** Resolves one language for a generated assistant response. */
class ResponseLanguageResolver
{
    public const MIN_QUESTION_CONFIDENCE = 0.75;

    /**
     * Priority: question language, manually selected language, browser
     * preferences in order, then the first language allowed by the tenant.
     */
    public function resolve(
        ?string $allowedLanguages,
        ?string $questionLanguage = null,
        float $questionConfidence = 0.0,
        ?string $manualLanguage = null,
        string|array|null $browserLanguages = null,
    ): string {
        $allowed = $this->allowedLanguages($allowedLanguages);

        $question = $this->normalize($questionLanguage);
        if (
            $question !== null
            && $questionConfidence >= self::MIN_QUESTION_CONFIDENCE
            && in_array($question, $allowed, true)
        ) {
            return $question;
        }

        $manual = $this->normalize($manualLanguage);
        if ($manual !== null && in_array($manual, $allowed, true)) {
            return $manual;
        }

        foreach ($this->asList($browserLanguages) as $browserLanguage) {
            $browser = $this->normalize($browserLanguage);
            if ($browser !== null && in_array($browser, $allowed, true)) {
                return $browser;
            }
        }

        return $allowed[0] ?? 'fr';
    }

    /** @return list<string> */
    public function allowedLanguages(?string $languages): array
    {
        $parsed = [];
        foreach (explode(',', (string) $languages) as $language) {
            $normalized = $this->normalize($language);
            if ($normalized !== null && ! in_array($normalized, $parsed, true)) {
                $parsed[] = $normalized;
            }
        }

        return $parsed !== [] ? $parsed : ['fr'];
    }

    public function languageName(string $language): string
    {
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'pt' => 'Portuguese',
        ][$language] ?? $language;
    }

    private function asList(string|array|null $languages): array
    {
        if (is_array($languages)) {
            return array_values(array_filter($languages, 'is_string'));
        }

        return is_string($languages) ? explode(',', $languages) : [];
    }

    private function normalize(?string $language): ?string
    {
        if (! is_string($language) || trim($language) === '') {
            return null;
        }

        $language = strtolower(str_replace('_', '-', trim($language)));
        if (! preg_match('/^([a-z]{2})(?:-[a-z0-9]{2,8})*$/', $language, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
