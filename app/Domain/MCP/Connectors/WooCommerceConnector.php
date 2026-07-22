<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;

/**
 * Connecteur WooCommerce (REST API v3, auth par clé/secret consumer).
 * settings attendus (mcp_site_connectors.settings) : { "store_url": "https://..." }
 * credentials attendus (déchiffrés) : { "consumer_key": "...", "consumer_secret": "..." }
 */
class WooCommerceConnector extends AbstractConnector
{
    public function slug(): string
    {
        return 'woocommerce';
    }

    public function listTools(): array
    {
        return [
            new ToolSchema(
                connectorSlug: $this->slug(),
                name: 'get_order_status',
                description: "Récupère le statut, le contenu et les infos de livraison d'une commande WooCommerce à partir de son numéro.",
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'string', 'description' => 'Numéro de commande'],
                    ],
                    'required' => ['order_id'],
                ],
                isWriteAction: false,
            ),
            new ToolSchema(
                connectorSlug: $this->slug(),
                name: 'search_orders_by_email',
                description: "Recherche les commandes récentes liées à un email site, utile quand le visiteur ne connaît pas son numéro de commande.",
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'description' => 'Email du site'],
                    ],
                    'required' => ['email'],
                ],
                isWriteAction: false,
            ),
            new ToolSchema(
                connectorSlug: $this->slug(),
                name: 'cancel_order',
                description: "Annule une commande WooCommerce. Action irréversible, à utiliser seulement si le site le demande explicitement.",
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['order_id'],
                ],
                isWriteAction: true,
            ),
        ];
    }

    public function authenticate(array $credentials): array
    {
        // Auth par clé API statique : pas de refresh token, on valide juste la présence des clés.
        if (empty($credentials['consumer_key']) || empty($credentials['consumer_secret'])) {
            throw new AuthExpiredException('Clés API WooCommerce manquantes ou invalides.');
        }

        return $credentials;
    }

    public function callTool(string $toolName, array $params, array $credentials): ToolResult
    {
        return match ($toolName) {
            'get_order_status' => $this->getOrderStatus($params, $credentials),
            'search_orders_by_email' => $this->searchOrdersByEmail($params, $credentials),
            'cancel_order' => $this->cancelOrder($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le connecteur woocommerce."),
        };
    }

    private function getOrderStatus(array $params, array $credentials): ToolResult
    {
        try {
            $response = $this->site($credentials)->get("orders/{$params['order_id']}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', "Aucune commande trouvée avec le numéro {$params['order_id']}.");
            }
            throw new ConnectorUnavailableException('WooCommerce indisponible: ' . $e->getMessage());
        }

        $this->recordSuccess();
        $order = $response->json();

        return ToolResult::ok([
            'order_id' => $order['id'],
            'status' => $order['status'],
            'total' => $order['total'],
            'currency' => $order['currency'],
            'shipping_method' => $order['shipping_lines'][0]['method_title'] ?? null,
            'tracking_note' => $this->extractTrackingInfo($order),
            'date_created' => $order['date_created'],
        ], "Commande #{$order['id']} : statut {$order['status']}");
    }

    private function searchOrdersByEmail(array $params, array $credentials): ToolResult
    {
        try {
            $response = $this->site($credentials)->get('orders', [
                'search' => $params['email'],
                'per_page' => 5,
                'orderby' => 'date',
                'order' => 'desc',
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('WooCommerce indisponible: ' . $e->getMessage());
        }

        $this->recordSuccess();
        $orders = collect($response->json())->map(fn ($o) => [
            'order_id' => $o['id'],
            'status' => $o['status'],
            'total' => $o['total'],
            'date_created' => $o['date_created'],
        ])->all();

        if (empty($orders)) {
            return ToolResult::fail('not_found', "Aucune commande trouvée pour {$params['email']}.");
        }

        return ToolResult::ok(['orders' => $orders], count($orders) . ' commande(s) trouvée(s)');
    }

    private function cancelOrder(array $params, array $credentials): ToolResult
    {
        try {
            $response = $this->site($credentials)->put("orders/{$params['order_id']}", [
                'status' => 'cancelled',
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', "Commande {$params['order_id']} introuvable.");
            }
            throw new ConnectorUnavailableException('WooCommerce indisponible: ' . $e->getMessage());
        }

        $this->recordSuccess();

        return ToolResult::ok(
            ['order_id' => $params['order_id'], 'status' => 'cancelled'],
            "Commande #{$params['order_id']} annulée."
        );
    }

    private function extractTrackingInfo(array $order): ?string
    {
        // Adapte selon le plugin de tracking utilisé (ex: meta_data 'tracking_number')
        foreach ($order['meta_data'] ?? [] as $meta) {
            if (str_contains(strtolower($meta['key']), 'tracking')) {
                return (string) $meta['value'];
            }
        }
        return null;
    }

    private function site(array $credentials)
    {
        $storeUrl = rtrim($credentials['store_url'] ?? '', '/');

        return $this->http("{$storeUrl}/wp-json/wc/v3/")
            ->withBasicAuth($credentials['consumer_key'], $credentials['consumer_secret']);
    }
}
