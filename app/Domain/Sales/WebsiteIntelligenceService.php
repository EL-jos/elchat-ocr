<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Analyse le site PUBLIC d'un prospect (pas un compte du tenant) : simple
 * fetch HTTP + heuristiques sur le HTML, pas d'OAuth, pas de connecteur MCP
 * générique — voir §11 du cahier des charges et §6 de l'architecture
 * (analyse d'un tiers, hors du modèle OAuth/api_key existant).
 *
 * Heuristiques volontairement simples et vérifiables (V1) — pas de LLM ici :
 * la lecture/interprétation des signaux revient au LLM APRÈS coup, dans
 * SalesHunterConnector::analyze_website, pas dans ce service.
 */
class WebsiteIntelligenceService
{
    private const KNOWN_CHAT_WIDGET_MARKERS = [
        'intercom', 'drift.com', 'crisp.chat', 'tawk.to', 'zendesk', 'livechatinc',
        'hubspot-chat', 'elchat', 'tidio', 'freshchat',
    ];

    // Sous-ensemble concurrent — exclut délibérément 'elchat' (un site déjà
    // client n'est pas son propre concurrent).
    private const COMPETITOR_MARKERS = [
        'intercom', 'drift.com', 'crisp.chat', 'tawk.to', 'zendesk', 'livechatinc',
        'hubspot-chat', 'tidio', 'freshchat',
    ];

    private const SOCIAL_DOMAINS = ['facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com', 'tiktok.com'];

    /**
     * @return array{has_chatbot:bool, contact_form_only:bool, social_activity_score:int, has_competitor_solution:bool, page_title:?string, fetch_error:?string}
     */
    public function analyze(string $url): array
    {
        $url = str_starts_with($url, 'http') ? $url : "https://{$url}";

        try {
            $response = Http::timeout(6)->withHeaders(['User-Agent' => 'ELChatSalesHunter/1.0 (+https://elchat.io)'])->get($url);
        } catch (\Throwable $e) {
            Log::warning("WebsiteIntelligenceService: échec fetch {$url}: {$e->getMessage()}");
            return $this->emptyResult('Site inaccessible.');
        }

        if ($response->failed()) {
            return $this->emptyResult("Le site a répondu avec un statut {$response->status()}.");
        }

        $html = mb_strtolower($response->body());

        return [
            'has_chatbot' => $this->containsAny($html, self::KNOWN_CHAT_WIDGET_MARKERS),
            'contact_form_only' => str_contains($html, '<form') && !$this->containsAny($html, self::KNOWN_CHAT_WIDGET_MARKERS),
            'social_activity_score' => collect(self::SOCIAL_DOMAINS)->filter(fn ($d) => str_contains($html, $d))->count(),
            // Heuristique volontairement prudente : ne signale une "solution concurrente"
            // que si le terme apparaît explicitement, jamais une déduction.
            'has_competitor_solution' => $this->containsAny($html, self::KNOWN_CHAT_WIDGET_MARKERS),
            'page_title' => $this->extractTitle($response->body()),
            'fetch_error' => null,
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) return true;
        }
        return false;
    }

    private function extractTitle(string $html): ?string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(strip_tags($m[1])) : null;
    }

    private function emptyResult(string $error): array
    {
        return [
            'has_chatbot' => false, 'contact_form_only' => false, 'social_activity_score' => 0,
            'has_competitor_solution' => false, 'page_title' => null, 'fetch_error' => $error,
        ];
    }
}
