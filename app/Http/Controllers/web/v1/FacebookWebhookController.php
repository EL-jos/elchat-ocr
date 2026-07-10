<?php

namespace App\Http\Controllers\web\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FacebookWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $payload = $request->all();

        // enregistrer event
        // pousser dans queue ELChat
    }

    public function verify(Request $request)
    {
        $mode = $request->get('hub_mode');
        $token = $request->get('hub_verify_token');
        $challenge = $request->get('hub_challenge');
        Log::info("WEBHOOK VERIFY", [
            "token" => $token,
            "challenge" => $challenge,
            "mode" => $mode
        ]);

        if (
            $mode === 'subscribe'
            && $token === config('services.facebook.verify_token')
        ) {
            return response($challenge, 200);
        }

        abort(403);
    }

    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature');

        if (!$signature) {
            return false;
        }

        [$algo, $hash] = explode('=', $signature);

        $expected = hash_hmac(
            'sha1',
            $request->getContent(),
            config('services.facebook.app_secret')
        );

        return hash_equals($expected, $hash);
    }

    public function handle(Request $request)
    {
        if (!$this->verifySignature($request)) {

            return response()->json([
                'message' => 'Invalid signature'
            ], 403);
        }

        $payload = $request->all();

        if (($payload['object'] ?? null) !== 'page') {

            return response()->json([], 404);
        }

        // Facebook veut un 200 immédiat
        response()->json([
            'success' => true
        ])->send();

        Log::info("Payload dans HANDLE du WEBHOOK", $payload);

        return;
    }
}
