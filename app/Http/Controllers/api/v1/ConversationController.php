<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
        ]);
        $conversations = Conversation::with('messages')
            ->where('site_id', $data['site_id'])
            ->where('user_id', auth()->id())
            ->get();

        return response()->json($conversations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }
    /**
     * Display the specified resource.
     */
    public function show(Conversation $conversation)
    {
        //
    }
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Conversation $conversation)
    {
        //
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Conversation $conversation)
    {
        // 🔐 Vérification propriétaire du site
        $this->authorizeSite($conversation->site);

        // 🔄 Supprimer les messages associés
        $conversation->messages()->delete();

        // 🔄 Supprimer la conversation elle-même
        $conversation->delete();

        // 🔄 Retourner succès
        return response()->json([
            'message' => 'Conversation supprimée avec succès.',
            'conversation_id' => $conversation->id
        ]);
    }

    public function messages(string $conversationId, string $siteId){
        $conversation = Conversation::where('id', $conversationId)
                        ->where('user_id', auth()->id())
                        ->where('site_id', $siteId)
                        ->with(['messages', 'messages.displayedCtas'])
                        ->first();

        return response()->json($conversation);
    }

    public function messagesByUser(string $conversationId, string $siteId, string $userId){
        $conversation = Conversation::where('id', $conversationId)
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->with(['messages', 'messages.displayedCtas'])
            ->first();

        //dd($conversation);

        return response()->json($conversation);
    }

    public function messagesAdmin(string $conversationId)
    {
        /*
        |--------------------------------------------------------------------------
        | Charger la conversation + site + account
        |--------------------------------------------------------------------------
        */

        $conversation = Conversation::with(['site.account'])
            ->findOrFail($conversationId);

        $this->authorizeSite($conversation->site);

        /*
        |--------------------------------------------------------------------------
        | Charger les messages
        |--------------------------------------------------------------------------
        */

        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc') // déjà global scope mais sécurité
            ->get(['id', 'conversation_id', 'user_id', 'role', 'content', 'created_at']);

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'conversation' => [
                'id'         => $conversation->id,
                'site_id'    => $conversation->site_id,
                'user_id'    => $conversation->user_id,
                'created_at' => $conversation->created_at,
            ],
            'messages' => $messages
        ]);
    }

    private function authorizeSite(Site $site)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->errorResponse(
                'Authentication required.',
                'AUTH_REQUIRED',
                401
            );
        }

        if (!$user->isAdmin()) {
            return $this->errorResponse(
                'Only administrators can access this resource.',
                'ADMIN_ONLY',
                403
            );
        }

        if ($site->account->owner_user_id !== $user->id) {
            return $this->errorResponse(
                'You are not the owner of this site.',
                'SITE_FORBIDDEN',
                403
            );
        }

        return null; // OK
    }

    protected function errorResponse(
        string $message,
        string $errorCode,
        int $status = 400
    ) {
        return response()->json([
            'message'    => $message,
            'error_code' => $errorCode,
        ], $status);
    }

    public function conversationsByUser(string $siteId, string $userId)
    {
        // 🔐 Récupérer le site + sécurisation
        $site = Site::findOrFail($siteId);
        $this->authorizeSite($site);

        // 🔎 Vérifier que l'utilisateur appartient au site (ManyToMany)
        $user = User::where('id', $userId)
            ->whereHas('sites', fn($q) => $q->where('sites.id', $siteId))
            ->firstOrFail();

        // 🔄 Récupérer les conversations de l'utilisateur pour ce site
        $conversations = Conversation::where('site_id', $siteId)
            ->where('user_id', $userId)
            ->withCount('messages') // nombre de messages
            ->with(['messages' => fn($q) => $q
                ->where('role', 'user') // 🔹 seulement les messages de l'utilisateur
                ->latest()
                ->limit(1)
            ]) // dernier message
            ->get();

        // 🔄 Formatage
        $formatted = $conversations->map(function ($conv) {
            $lastMessage = $conv->messages->first();

            return [
                'id'           => $conv->id,
                'created_at'   => $conv->created_at,
                'messages_count' => $conv->messages_count,
                'last_message' => $lastMessage ? [
                    'content'    => $lastMessage->content,
                    'created_at' => $lastMessage->created_at,
                ] : null,
            ];
        });

        return response()->json($formatted);
    }
}
