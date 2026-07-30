<?php

namespace App\Domain\MCP\Contracts;

/**
 * Implémentée uniquement par les connecteurs dont la liste d'outils dépend
 * de l'instance connectée (ex: Odoo — modules installés variables selon
 * l'abonnement du tenant). Les connecteurs classiques (WooCommerce,
 * HubSpot...) n'implémentent pas cette interface et continuent d'utiliser
 * listTools() sans changement.
 */
interface ProvidesSiteScopedTools
{
    /** @return ToolSchema[] */
    public function toolsAvailableFor(array $credentials): array;
}
