<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\AnalyticsAttributionType;
use App\Enums\AnalyticsEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateConversationStatusRequest;
use App\Http\Resources\ConversationDetailResource;
use App\Http\Resources\ConversationListResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Models\User;
use App\Services\conversation\VisitorConversionService;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\MessageResource;

class ConversationController extends Controller
{
    public function __construct(
        private readonly VisitorConversionService $conversionService,
        private readonly AnalyticsEventService $analytics,
    ) {
    }

    /**
     * GET /api/sites/{siteId}/conversations
     * Liste paginée pour la sidebar, filtrable par statut et recherche
     * (résumé, nom/email de l'utilisateur rattaché).
     */
    public function index(Request $request, string $siteId): JsonResponse
    {
        $site = Site::findOrFail($siteId);
        $this->authorizeSite($site);

        $perPage = (int) $request->integer('per_page', 20);

        $query = Conversation::query()
            ->where('site_id', $siteId)
            ->with(['user'])
            ->withCount('messages')
            ->orderByDesc('updated_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('summary', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('firstname', 'like', "%{$search}%")
                            ->orWhere('lastname', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => ConversationListResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }
    /**
     * GET /api/sites/{siteId}/conversations/{conversation}
     *
     * Bundle "meta" complet : visiteur/utilisateur, résumé, mémoire structurée,
     * dernière soumission de formulaire. Les messages restent chargés séparément
     * via l'endpoint paginé déjà existant (GET /conversation/{id}/messages),
     * pour ne pas dupliquer une pagination qui fonctionne déjà.
     */
    public function show(string $siteId, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->site_id === $siteId, 404);
        $this->authorizeSite($conversation->site);

        $conversation->load([
            'visitor',
            'user',
            'memory',
            'formSubmissions' => fn ($q) => $q->latest()->limit(1)->with('files'),
        ]);

        return response()->json(new ConversationDetailResource($conversation));
    }
    /**
     * PATCH /api/sites/{siteId}/conversations/{conversation}/status
     */
    public function updateStatus(UpdateConversationStatusRequest $request, string $siteId, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->site_id === $siteId, 404);
        $this->authorizeSite($conversation->site);

        $conversation->update(['status' => $request->validated('status')]);

        if ($conversation->status === 'open') {
            $site = Site::findOrFail($siteId);
            $this->analytics->capture(
                $site,
                AnalyticsEventType::HUMAN_HANDOFF,
                [
                    'visitor_id' => $conversation->visitor_id,
                    'conversation_id' => $conversation->id,
                    'session_id' => $conversation->metadata['session_id'] ?? null,
                    'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                    'source' => 'admin',
                    'channel' => $conversation->metadata['channel'] ?? 'widget',
                ],
                idempotencyKey: $this->analytics->deterministicKey('human_handoff', $conversation->id),
            );
        }

        if ($conversation->status === 'resolved') {
            $site = Site::findOrFail($siteId);
            $this->analytics->capture(
                $site,
                AnalyticsEventType::CONVERSATION_RESOLVED,
                [
                    'visitor_id' => $conversation->visitor_id,
                    'conversation_id' => $conversation->id,
                    'session_id' => $conversation->metadata['session_id'] ?? null,
                    'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                    'source' => 'admin',
                    'channel' => $conversation->metadata['channel'] ?? 'widget',
                ],
                metadata: ['resolution_mode' => 'human'],
                idempotencyKey: $this->analytics->deterministicKey('conversation_resolved', $conversation->id),
            );
        }

        return response()->json(['success' => true, 'status' => $conversation->status]);
    }

    /**
     * POST /api/sites/{siteId}/conversations/{conversation}/convert-to-user
     */
    public function convertToUser(string $siteId, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->site_id === $siteId, 404);
        $this->authorizeSite($conversation->site);

        $result = $this->conversionService->convert($conversation);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        $site = Site::findOrFail($siteId);
        $this->analytics->capture(
            $site,
            AnalyticsEventType::LEAD_UPDATED,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'session_id' => $conversation->metadata['session_id'] ?? null,
                'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                'resource_type' => 'lead',
                'resource_id' => (string) ($result['user']['id'] ?? $conversation->user_id),
                'source' => 'admin_conversion',
                'channel' => $conversation->metadata['channel'] ?? 'widget',
                'attribution_type' => AnalyticsAttributionType::ASSISTED->value,
            ],
            idempotencyKey: $this->analytics->deterministicKey('lead_updated', $conversation->id),
        );

        return response()->json($result);
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

    private function authorizeSite(Site $site): void
    {
        $user = auth()->user();
        abort_unless($user, 401, 'Authentication required.');
        abort_unless($user->isAdmin(), 403, 'Only administrators can access this resource.');
        abort_unless($site->account->owner_user_id === $user->id, 403, 'You are not the owner of this site.');
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

    /**
     * GET /v1/site/{siteId}/conversations/{conversation}/messages
     *
     * Messages paginés et enrichis (CTAs affichées, pièce jointe, soumissions de
     * formulaire) pour le panneau de l'onglet admin "Conversations".
     * Distincte de messages()/messagesAdmin()/messagesByUser() : celles-ci ont
     * des scopes d'autorisation différents (visiteur propriétaire, ou aucun
     * filtre de site) et ne renvoient pas un format paginé enrichi.
     */
    public function adminMessages(Request $request, string $siteId, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->site_id === $siteId, 404);
        $this->authorizeSite($conversation->site);

        $perPage = (int) $request->integer('per_page', 30);

        $paginator = $conversation->messages()
            ->with(['displayedCtas', 'chatFormSubmissions.files'])
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => MessageResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }
}
