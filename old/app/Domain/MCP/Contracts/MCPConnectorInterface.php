<?php

namespace App\Domain\MCP\Contracts;

/**
 * Contrat que TOUT connecteur doit respecter.
 *
 * C'est la pièce centrale de la scalabilité : l'orchestrateur, le moteur de
 * permissions et le registre ne parlent jamais à WooCommerce ou à Google
 * Calendar directement — ils parlent à cette interface. Ajouter Stripe,
 * HubSpot ou Gmail demain = une nouvelle classe qui l'implémente, enregistrée
 * dans config/mcp.php. Rien d'autre ne change.
 */
interface MCPConnectorInterface
{
    /**
     * Identifiant unique et stable du connecteur (doit correspondre à
     * mcp_connectors.slug). Ex: 'woocommerce', 'google_calendar'.
     */
    public function slug(): string;

    /**
     * Liste des outils exposés par ce connecteur, au format function-calling
     * (compatible OpenAI/Anthropic tool schema). Utilisé par l'orchestrateur
     * pour construire la liste de tools disponibles pour le LLM.
     *
     * @return ToolSchema[]
     */
    public function listTools(): array;

    /**
     * Vérifie que les identifiants stockés pour ce site sont valides et
     * rafraîchit le token si nécessaire (ex: refresh_token OAuth2).
     * Doit lever AuthExpiredException si la ré-authentification manuelle
     * est requise.
     */
    public function authenticate(array $credentials): array;

    /**
     * Exécute un outil. Reçoit les identifiants déjà déchiffrés du site
     * (jamais stockés par le connecteur lui-même) et les paramètres validés
     * par le PermissionEngine en amont.
     *
     * Doit toujours retourner un ToolResult, même en cas d'erreur métier
     * (commande introuvable, créneau déjà pris...) — les exceptions sont
     * réservées aux erreurs techniques (réseau, auth, timeout).
     *
     * $context (🆕) : injecté par MCPActionGateService, jamais fourni par le
     *  LLM. Contient site_id, conversation_id, owner_type/owner_id (panier,
     *  wishlist) et is_admin. Les connecteurs qui n'en ont pas besoin
     *  (GoogleCalendarConnector) peuvent l'ignorer.
     */
    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult;

    /**
     * Timeout par défaut en secondes pour tout appel réseau de ce connecteur.
     */
    public function defaultTimeout(): int;
}
