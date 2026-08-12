<?php

namespace App\Domain\Sales\Contracts;

use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Une source produit des CANDIDATS (pas encore des Prospect persistés) —
 * la déduplication et la persistance restent centralisées dans
 * ProspectDiscoveryService, jamais dans une source individuelle.
 *
 * $conversation : conversation interne synthétique au niveau CAMPAGNE
 * (distincte de la conversation par-PROSPECT créée ensuite pour la
 * qualification) — permet à la source d'exécuter des appels d'outils via
 * MCPActionGateService::executeToolDirectly() en réutilisant permissions +
 * audit, sans qu'un LLM décide s'il faut chercher (c'est déjà décidé).
 *
 * V1 : seule CrmColdContactSource est implémentée (voir architecture,
 * §9 — aucun scraping, uniquement des sources déjà autorisées/disponibles).
 * GooglePlacesProspectSource / WebsiteProspectSource / SocialProspectSource
 * s'ajoutent plus tard sans toucher au moteur.
 */
interface ProspectSourceInterface
{
    /** Identifiant court affiché comme `source` sur le Prospect créé. */
    public function key(): string;

    /**
     * @param array $icp {sector, company_type, location, company_size, custom_criteria}
     * @return Collection<int, array> candidats bruts : {name?, company?, website?, domain?, email?, phone?, location?, sector?, crm_ref?}
     */
    public function discover(Site $site, Conversation $conversation, array $icp, int $limit): Collection;
}
