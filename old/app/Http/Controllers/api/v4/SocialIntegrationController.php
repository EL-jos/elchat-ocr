<?php

namespace App\Http\Controllers\api\v4;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\Services\MercureService;
use App\Services\Social\SocialReplyDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialIntegrationController extends Controller
{

    public function __construct(
        protected MercureService $mercure,   // 👈 AJOUT — injection
        protected SocialReplyDispatcher $dispatcher, // 👈 AJOUT
    ) {}

    public function integrations(string $siteId){

        $accountId = auth()->user()->ownedAccount->id;

        if (!$accountId){}

        /**
         * @var Site $site
         */
        $site = Site::where('id', $siteId)
            ->where('account_id', $accountId)
            ->firstOrFail();

        if (!$site){}

        $site->socialAccounts->each(function ($account){
            $account->load('conversations.messages.reply');
        });

        return response()->json($site->socialAccounts);
    }

    public function setAutoReply(Request $request, string $siteId): JsonResponse
    {
        $request->validate([
            'provider'   => ['required', 'string', 'in:facebook,instagram,whatsapp,telegram,imap,youtube'],
            'auto_reply' => ['required', 'boolean'],
        ]);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $request->user()->ownedAccount->id)
            ->firstOrFail();

        // 🔍 Vérifier qu'au moins un SocialAccount actif existe pour ce provider
        $socialAccount = SocialAccount::where('site_id', $site->id)
            ->where('provider', $request->provider)
            ->where('is_active', true)
            ->first();

        if (!$socialAccount) {
            return response()->json([
                'success'    => false,
                'message'    => 'Aucun compte ' . ucfirst($request->provider) . ' connecté sur ce site.',
                'auto_reply' => false,
            ], 404);
        }

        // ✅ Mettre à jour tous les comptes actifs de ce provider sur ce site
        // (cas multi-pages Facebook : auto_reply s'applique à toutes les pages)
        $updated = SocialAccount::where('site_id', $site->id)
            ->where('provider', $request->provider)
            ->where('is_active', true)
            ->update(['auto_reply' => $request->boolean('auto_reply')]);

        Log::info('[SocialAccount] Auto-reply updated', [
            'site_id'    => $site->id,
            'provider'   => $request->provider,
            'auto_reply' => $request->boolean('auto_reply'),
            'rows'       => $updated,
        ]);

        return response()->json([
            'success'    => true,
            'message'    => 'Auto-reply ' . ($request->boolean('auto_reply') ? 'activé' : 'désactivé'),
            'auto_reply' => $request->boolean('auto_reply'),
            'updated'    => $updated, // nombre de pages mises à jour (utile pour le debug)
        ]);
    }

    public function reply(Request $request, string $siteId, string $provider, string $conversationId){

        $request->validate([
            'messageId'   => ['required', 'uuid', 'exists:social_messages,id'],
            'message' => ['required', 'string'],
        ]);

        /** @var Site $site */
        $site = Site::where('id', $siteId)
            ->where('account_id', $request->user()->ownedAccount->id)
            ->firstOrFail();

        // 🔍 Vérifier qu'au moins un SocialAccount actif existe pour ce provider
        $socialAccount = SocialAccount::where('site_id', $site->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (!$socialAccount) {
            return response()->json([
                'success'    => false,
                'message'    => 'Aucun compte ' . ucfirst($request->provider) . ' connecté sur ce site.',
                'auto_reply' => false,
            ], 404);
        }

        $message = SocialMessage::where('id', $request->messageId)
                                ->where('provider', $provider)
                                ->where('social_conversation_id', $conversationId)->first();
        if (!$message){}

        $isUpdate = $message->update(['content' => $request->message]);

        if(!$isUpdate){}

        $reply = $message->reply;

        try {

            $this->dispatcher->dispatch($reply); // async, non bloquant

        }catch( \Throwable $e) {
            // Déjà loggué dans le dispatcher — on ne bloque pas le process
            Log::error('[SocialReplyEngine] Auto-dispatch échoué', [
                'reply_id' => $reply->id,
                'error'    => $e->getMessage(),
            ]);
        }


        // ──────────────────────────────────────────────────────
        // 👈 AJOUT — Publier sur Mercure pour mise à jour RT
        // ──────────────────────────────────────────────────────

        $socialAccount->load(['conversations.messages', 'events', 'users', 'site']);

        try {
            $this->mercure->post(
                topic: "site/{$site->id}/integrations",
                data: [
                    'event'           => 'new_message',
                    'socialAccount'  => $socialAccount,
                    'provider'=> $provider,
                ]
            );
        } catch (\Throwable $e) {
            // Mercure ne doit jamais bloquer le traitement
            Log::warning('[SocialIntegrationController] Mercure publish failed', [
                'error'   => $e->getMessage(),
                'site_id' => $site->id,
                'provider'=> $provider,
            ]);
        }
    }
}
