<?php

namespace App\Http\Controllers\web\v5;

use App\Domain\Email\EmailService;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessEmailEventJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Un seul contrôleur pour TOUS les fournisseurs — la route détermine quel
 * provider gère la requête (voir routes), EmailService reste agnostique.
 * Générique par construction : ajouter Mailgun ne touche pas ce fichier.
 */
class EmailEventWebhookController extends Controller
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function handle(Request $request)
    {
        // Gère l'éventuelle poignée de main du fournisseur (ex: confirmation
        // d'abonnement SNS) AVANT toute vérification de signature d'événement —
        // la poignée de main a sa propre vérification interne au provider.
        $handshake = $this->emailService->handleWebhookHandshake($request);
        if ($handshake) {
            return response()->json($handshake, 200);
        }

        if (!$this->emailService->verifyEventWebhookSignature($request)) {
            Log::warning('EmailEventWebhook: signature invalide, requête rejetée.');
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $events = $this->emailService->parseEventWebhook($request);
        foreach ($events as $event) {
            ProcessEmailEventJob::dispatch($event);
        }

        return response()->json(['status' => 'accepted'], 200);
    }
}
