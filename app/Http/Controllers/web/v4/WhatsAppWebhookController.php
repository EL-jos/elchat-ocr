<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * GET
     * Vérification du webhook Meta.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode')
            ?? $request->query('hub.mode');

        $token = $request->query('hub_verify_token')
            ?? $request->query('hub.verify_token');

        $challenge = $request->query('hub_challenge')
            ?? $request->query('hub.challenge');

        Log::info('[WhatsApp][Webhook] VERIFY', [
            'mode'      => $mode,
            'token'     => $token,
            'challenge' => $challenge,
        ]);

        if (
            $mode !== 'subscribe' ||
            $token !== config('services.facebook.webhook_verify_token')
        ) {
            abort(403);
        }

        return response($challenge, 200);
    }

    /**
     * POST
     * Réception des événements WhatsApp.
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('[WhatsApp] Incoming webhook.', [
            'payload' => $payload,
        ]);

        /**
         * Sécurité.
         */
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json([
                'success' => true,
            ]);
        }

        /**
         * Un SocialEvent par webhook.
         */
        $event = SocialEvent::create([
            'id' => (string) Str::uuid(),

            'provider' => 'whatsapp',

            'event_type' => 'webhook',

            'payload' => $payload,

            'processing_status' => 'pending',

            'received_at' => now(),
        ]);

        /*WhatsAppWebhookJob::dispatch(
            $event->id
        );*/

        /**
         * Toujours répondre rapidement à Meta.
         */
        return response()->json([
            'success' => true,
        ]);
    }
}
