<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Controller;
use App\Services\payment\SubscriptionOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Cible des return_url / cancel_url PayPal — jamais appelée par Angular directement.
 * PayPal ouvre cette page dans la popup ouverte par le frontend ; elle communique
 * le résultat au parent via window.postMessage puis se ferme automatiquement.
 *
 * Sécurité : on ne fait jamais confiance aux query params pour l'état du paiement —
 * seul le statut retourné par l'API PayPal (via retrieveSubscriptionStatus) fait foi.
 */
class PaypalCheckoutReturnController extends Controller
{
    public function __construct(private SubscriptionOrchestrator $orchestrator) {}

    public function handle(Request $request): Response
    {
        $subscriptionId = $request->query('subscription_id');
        $accountId      = $request->query('account_id');

        if (!$subscriptionId || !$accountId) {
            return $this->popupResponse('error', 'Paramètres manquants.');
        }

        try {
            $this->orchestrator->confirmPurchase($accountId, $subscriptionId);
            return $this->popupResponse('success', 'Paiement confirmé.', $subscriptionId);
        } catch (\Throwable $e) {
            Log::error('PaypalCheckoutReturnController: confirmation failed', ['error' => $e->getMessage()]);
            return $this->popupResponse('error', $e->getMessage());
        }
    }

    public function cancel(Request $request): Response
    {
        return $this->popupResponse('canceled', 'Paiement annulé.');
    }

    private function popupResponse(string $status, string $message, ?string $subscriptionId = null): Response
    {
        $payload = json_encode([
            'type'            => 'elchat-paypal-return',
            'status'          => $status,
            'message'         => $message,
            'subscription_id' => $subscriptionId,
        ]);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;text-align:center;padding:60px 20px;color:#334155;">
    <p>{$message}</p>
    <p style="font-size:13px;color:#94a3b8;">Cette fenêtre va se fermer automatiquement…</p>
    <script>
        if (window.opener) {
            window.opener.postMessage({$payload}, '*');
        }
        window.close();
    </script>
</body>
</html>
HTML;

        return response($html);
    }
}
