<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Site;
use App\Models\Page;
use App\Models\Document;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function overview(Request $request)
    {
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(6);

        $account = auth()->user()->ownedAccount;
        if (!$account) {
            return response()->json(['error' => 'No owned account'], 404);
        }

        $sites = Site::where('account_id', $account->id)->with('type')->get();
        $siteIds = $sites->pluck('id');

        //dd($sites->);

        // =====================
        // 🔢 TOTAUX GLOBAUX
        // =====================
        $total_sites = $sites->count();

        $total_documents = Document::whereIn('documentable_id', $siteIds)
            ->where('documentable_type', Site::class)
            ->count();

        $conversationIds = Conversation::whereIn('site_id', $siteIds)->pluck('id');

        $total_conversations = $conversationIds->count();
        $total_messages = Message::whereIn('conversation_id', $conversationIds)->count();

        // 🔹 Nombre total d’utilisateurs liés aux sites
        $total_users = DB::table('site_user')
            ->whereIn('site_id', $siteIds)
            ->distinct('user_id')
            ->count('user_id');

        // =====================
        // 📆 PÉRIODE (7 JOURS)
        // =====================
        $period = collect();
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $period->push($d->format('Y-m-d'));
        }

        $conversations_per_day = [];
        $messages_per_day = [];
        $source_distribution = [];

        foreach ($sites as $site) {
            // =====================
            // 💬 CONVERSATIONS / SITE
            // =====================
            $siteConversations = Conversation::where('site_id', $site->id)
                ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                ->get();

            $siteConversationIds = $siteConversations->pluck('id');

            $siteMessages = Message::whereIn('conversation_id', $siteConversationIds)
                ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                ->get();

            $conversations_per_day[] = [
                'site_name' => $site->name,
                'data' => $period->map(fn ($day) => [
                    'date' => $day,
                    'count' => $siteConversations
                        ->whereBetween('created_at', [$day.' 00:00:00', $day.' 23:59:59'])
                        ->count()
                ])
            ];

            $messages_per_day[] = [
                'site_name' => $site->name,
                'data' => $period->map(fn ($day) => [
                    'date' => $day,
                    'count' => $siteMessages
                        ->whereBetween('created_at', [$day.' 00:00:00', $day.' 23:59:59'])
                        ->count()
                ])
            ];

            // =====================
            // 📦 SOURCE DISTRIBUTION (SQL COUNT)
            // =====================
            $siteDocumentIds = Document::where('documentable_id', $site->id)
                ->where('documentable_type', Site::class)
                ->pluck('id');

            $pageIds = Page::where('site_id', $site->id)->pluck('id');

            // Calcul des totaux par source directement en SQL
            $bySource = [
                'crawl' => Chunk::where(function($q) use ($siteDocumentIds, $pageIds){
                    $q->whereIn('document_id', $siteDocumentIds)
                        ->orWhereIn('page_id', $pageIds);
                })->where('source_type', 'crawl')->count(),
                'woocommerce' => Chunk::where(function($q) use ($siteDocumentIds, $pageIds){
                    $q->whereIn('document_id', $siteDocumentIds)
                        ->orWhereIn('page_id', $pageIds);
                })->where('source_type', 'woocommerce')->count(),
                'manuel' => Chunk::where(function($q) use ($siteDocumentIds, $pageIds){
                    $q->whereIn('document_id', $siteDocumentIds)
                        ->orWhereIn('page_id', $pageIds);
                })->where('source_type', 'manuel')->count(),
                'sitemap' => Chunk::where(function($q) use ($siteDocumentIds, $pageIds){
                    $q->whereIn('document_id', $siteDocumentIds)
                        ->orWhereIn('page_id', $pageIds);
                })->where('source_type', 'sitemap')->count(),
            ];

            $source_distribution[] = [
                'site_name' => $site->name,
                'sources' => $bySource
            ];
        }

        return response()->json([
            'total_sites' => $total_sites,
            'total_documents' => $total_documents,
            'total_conversations' => $total_conversations,
            'total_messages' => $total_messages,
            'total_users' => $total_users,
            'sites' => $sites,
            'conversations_per_day' => $conversations_per_day,
            'messages_per_day' => $messages_per_day,
            'source_distribution' => $source_distribution,
        ]);
    }
    //Bonne version
    /*public function overview(Request $request)
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays(6)->startOfDay();

        $account = auth()->user()->ownedAccount;
        if (!$account) {
            return response()->json(['error' => 'No owned account'], 404);
        }

        $sites = Site::where('account_id', $account->id)
            ->with('type')
            ->get();

        $siteIds = $sites->pluck('id');

        // =====================
        // 🔢 TOTAUX GLOBAUX
        // =====================
        $total_sites = $sites->count();

        $total_documents = Document::whereIn('documentable_id', $siteIds)
            ->where('documentable_type', Site::class)
            ->count();

        $conversationIds = Conversation::whereIn('site_id', $siteIds)->pluck('id');

        $total_conversations = $conversationIds->count();
        $total_messages = Message::whereIn('conversation_id', $conversationIds)->count();

        $total_users = DB::table('site_user')
            ->whereIn('site_id', $siteIds)
            ->distinct()
            ->count('user_id');

        // =====================
        // 📆 PÉRIODE (7 JOURS)
        // =====================
        $period = collect();
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $period->push($d->copy());
        }

        $conversations_per_day = [];
        $messages_per_day = [];
        $source_distribution = [];

        foreach ($sites as $site) {

            // =====================
            // 💬 CONVERSATIONS & MESSAGES (1 requête)
            // =====================
            $siteConversations = Conversation::where('site_id', $site->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->get();

            $siteConversationIds = $siteConversations->pluck('id');

            $siteMessages = Message::whereIn('conversation_id', $siteConversationIds)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->get();

            // =====================
            // 📊 CONVERSATIONS / JOUR (PHP)
            // =====================
            $conversations_per_day[] = [
                'site_id'   => $site->id,
                'site_name' => $site->name,
                'data'      => $period->map(function ($day) use ($siteConversations) {
                    return [
                        'date'  => $day->format('Y-m-d'),
                        'count' => $siteConversations->filter(function ($c) use ($day) {
                            return $c->created_at->isSameDay($day);
                        })->count(),
                    ];
                })->values(),
            ];

            // =====================
            // 📊 MESSAGES / JOUR (PHP)
            // =====================
            $messages_per_day[] = [
                'site_id'   => $site->id,
                'site_name' => $site->name,
                'data'      => $period->map(function ($day) use ($siteMessages) {
                    return [
                        'date'  => $day->format('Y-m-d'),
                        'count' => $siteMessages->filter(function ($m) use ($day) {
                            return $m->created_at->isSameDay($day);
                        })->count(),
                    ];
                })->values(),
            ];

            // =====================
            // 📦 SOURCE DISTRIBUTION
            // =====================
            $siteDocumentIds = Document::where('documentable_id', $site->id)
                ->where('documentable_type', Site::class)
                ->pluck('id');

            $pageIds = Page::where('site_id', $site->id)->pluck('id');

            $source_distribution[] = [
                'site_id'   => $site->id,
                'site_name' => $site->name,
                'sources'   => [
                    'crawl' => Chunk::where(function ($q) use ($siteDocumentIds, $pageIds) {
                        $q->whereIn('document_id', $siteDocumentIds)
                            ->orWhereIn('page_id', $pageIds);
                    })->where('source_type', 'crawl')->count(),

                    'woocommerce' => Chunk::where(function ($q) use ($siteDocumentIds, $pageIds) {
                        $q->whereIn('document_id', $siteDocumentIds)
                            ->orWhereIn('page_id', $pageIds);
                    })->where('source_type', 'woocommerce')->count(),

                    'manuel' => Chunk::where(function ($q) use ($siteDocumentIds, $pageIds) {
                        $q->whereIn('document_id', $siteDocumentIds)
                            ->orWhereIn('page_id', $pageIds);
                    })->where('source_type', 'manuel')->count(),

                    'sitemap' => Chunk::where(function ($q) use ($siteDocumentIds, $pageIds) {
                        $q->whereIn('document_id', $siteDocumentIds)
                            ->orWhereIn('page_id', $pageIds);
                    })->where('source_type', 'sitemap')->count(),
                ],
            ];
        }

        return response()->json([
            'total_sites'         => $total_sites,
            'total_documents'     => $total_documents,
            'total_conversations' => $total_conversations,
            'total_messages'      => $total_messages,
            'total_users'         => $total_users,
            'sites'               => $sites,
            'conversations_per_day' => $conversations_per_day,
            'messages_per_day'      => $messages_per_day,
            'source_distribution'   => $source_distribution,
        ]);
    }*/
    public function siteOverview(Request $request, string $siteId)
    {
        //$site = Site::findOrFail($id);
        $user = auth()->user();
        $account = $user->ownedAccount;

        $site = Site::where('id', $siteId)
            ->where('account_id', auth()->user()->ownedAccount->id)
            ->with(['type', 'account', 'users'])
            ->firstOrFail();

        abort_if($site->account_id !== $account->id, 403);

        $endDate = \Carbon\Carbon::now();
        $startDate = \Illuminate\Support\Carbon::now()->subDays(6);

        // ---------------------
        // Documents
        // ---------------------
        $documentIds = Document::where('documentable_id', $site->id)
            ->where('documentable_type', Site::class)
            ->pluck('id');

        // ---------------------
        // Pages
        // ---------------------
        $pageIds = Page::where('site_id', $site->id)->pluck('id');

        // ---------------------
        // Chunks stats (SQL direct pour éviter la mémoire)
        // ---------------------
        $totalChunks = Chunk::where(function ($q) use ($documentIds, $pageIds) {
            $q->whereIn('document_id', $documentIds)
                ->orWhereIn('page_id', $pageIds);
        })->count();

        $bySource = [
            'crawl'       => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'crawl')->count(),
            'sitemap'     => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'sitemap')->count(),
            'manuel'      => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'manuel')->count(),
            'woocommerce' => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'woocommerce')->count(),
        ];

        // ---------------------
        // Chunks items (20 derniers)
        // ---------------------
        /*$chunkItems = Chunk::where(function ($q) use ($documentIds, $pageIds) {
            $q->whereIn('document_id', $documentIds)
                ->orWhereIn('page_id', $pageIds);
        })->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();*/

        // ---------------------
        // Conversations & messages
        // ---------------------
        $conversations = Conversation::where('site_id', $site->id)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        $conversationIds = $conversations->pluck('id');

        $messages = Message::whereIn('conversation_id', $conversationIds)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        // ---------------------
        // Users
        // ---------------------
        $total_users = DB::table('site_user')
            ->where('site_id', $site->id)
            ->distinct('user_id')
            ->count('user_id');

        // ---------------------
        // Période 7 jours
        // ---------------------
        $period = collect();
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $period->push($d->format('Y-m-d'));
        }

        $conversations_per_day = $period->map(fn ($day) => [
            'date' => $day,
            'count' => $conversations
                ->whereBetween('created_at', [$day.' 00:00:00', $day.' 23:59:59'])
                ->count()
        ]);

        $messages_per_day = $period->map(fn ($day) => [
            'date' => $day,
            'count' => $messages
                ->whereBetween('created_at', [$day.' 00:00:00', $day.' 23:59:59'])
                ->count()
        ]);

        $users = $site->users()
            ->select([
                'users.id',
                'users.firstname',
                'users.lastname',
                'users.email',
                'users.is_verified',
            ])
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'role_id' => $user->role_id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'is_verified' => (bool) $user->is_verified,
                'first_seen_at' => $user->pivot->first_seen_at,
                'last_seen_at' => $user->pivot->last_seen_at,
                'socialAccounts' => $user->socialAccounts,
            ]);

        // ---------------------
        // Settings
        // ---------------------
        $settings_crawl = [
            'language' => $site->language,
            'crawl_depth' => $site->crawl_depth,
            'crawl_delay' => $site->crawl_delay,
            'include_pages' => $site->include_pages,
            'exclude_pages' => $site->exclude_pages,
            'system_prompt' => $site->system_prompt,
            'updated_at' => $site->updated_at,
        ];

        $settings = $site->settings;
        if($settings){
            $settings->load('aiRole');
        }

        // ---------------------
        // Response
        // ---------------------
        return response()->json([
            'site' => $site,

            'kpis' => [
                'documents' => $documentIds->count(),
                'chunks' => $totalChunks,          // ✅ total chunks
                'conversations' => $conversations->count(),
                'messages' => $messages->count(),
                'users' => $total_users,
                "nb_pages" => $site->pages()->count(),
            ],

            'activity' => [
                'conversations_per_day' => $conversations_per_day,
                'messages_per_day' => $messages_per_day,
            ],

            'sources' => [
                'distribution' => $bySource,
            ],

            'chunks' => [
                'total' => $totalChunks,           // ✅ total
                'by_source' => $bySource,          // ✅ stats par source
                //'items' => $chunkItems,            // ✅ seulement 20 derniers
            ],

            'users' => $users,

            'settings_crawl' => $settings_crawl,

            'settings' => $settings,

            'knowledge_quality_socre' => $site->knowledgeQualityScore,

            'evaluations' => $site->ragEvaluationRuns->makeHidden(["total_queries", "retrieval_score", "answer_quality_score", "groundedness_score", "ranking_score", "generation_score", "hallucination_rate", "metrics_breakdown"])
        ]);
    }
    /*
    {
        $site = Site::findOrFail($siteId);
        $user = auth()->user();
        $account = $user->ownedAccount;

        abort_if($site->account_id !== $account->id, 403);

        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(6);

        // ---------------------
        // Documents
        // ---------------------
        $documentIds = Document::where('documentable_id', $site->id)
            ->where('documentable_type', Site::class)
            ->pluck('id');

        // ---------------------
        // Pages
        // ---------------------
        $pageIds = Page::where('site_id', $site->id)->pluck('id');

        // ---------------------
        // Chunks stats (SQL direct pour éviter la mémoire)
        // ---------------------
        $totalChunks = Chunk::where(function ($q) use ($documentIds, $pageIds) {
            $q->whereIn('document_id', $documentIds)
                ->orWhereIn('page_id', $pageIds);
        })->count();

        $bySource = [
            'crawl'       => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'crawl')->count(),
            'sitemap'     => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'sitemap')->count(),
            'manuel'      => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'manuel')->count(),
            'woocommerce' => Chunk::where(function ($q) use ($documentIds, $pageIds) {
                $q->whereIn('document_id', $documentIds)
                    ->orWhereIn('page_id', $pageIds);
            })->where('source_type', 'woocommerce')->count(),
        ];

        // ---------------------
        // Chunks items (20 derniers)
        // ---------------------
        $chunkItems = Chunk::where(function ($q) use ($documentIds, $pageIds) {
            $q->whereIn('document_id', $documentIds)
                ->orWhereIn('page_id', $pageIds);
        })->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // ---------------------
        // Conversations & messages
        // ---------------------
        $conversations = Conversation::where('site_id', $site->id)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        $conversationIds = $conversations->pluck('id');

        $messages = Message::whereIn('conversation_id', $conversationIds)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        // ---------------------
        // Users
        // ---------------------
        $total_users = DB::table('site_user')
            ->where('site_id', $site->id)
            ->distinct('user_id')
            ->count('user_id');

        // ---------------------
        // Période 7 jours
        // ---------------------
        $period = collect();
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $period->push($d->format('Y-m-d'));
        }

        $conversations_per_day = $period->map(fn ($day) => [
            'date' => $day,
            'count' => $conversations
                ->whereBetween('created_at', [$day.' 00:00:00', $day.' 23:59:59'])
                ->count()
        ]);

        $messages_per_day = $period->map(fn ($day) => [
            'date' => $day,
            'count' => $messages
                ->whereBetween('created_at', [$day.' 00:00:00', $day.' 23:59:59'])
                ->count()
        ]);

        // ---------------------
        // Settings
        // ---------------------
        $settings = [
            'language' => $site->language,
            'crawl_depth' => $site->crawl_depth,
            'crawl_delay' => $site->crawl_delay,
            'include_pages' => $site->include_pages,
            'exclude_pages' => $site->exclude_pages,
            'system_prompt' => $site->system_prompt,
            'updated_at' => $site->updated_at,
        ];

        // ---------------------
        // Response
        // ---------------------
        return response()->json([
            'site' => $site->load('type'),

            'kpis' => [
                'documents' => $documentIds->count(),
                'chunks' => $totalChunks,          // ✅ total chunks
                'conversations' => $conversations->count(),
                'messages' => $messages->count(),
                'users' => $total_users,
            ],

            'activity' => [
                'conversations_per_day' => $conversations_per_day,
                'messages_per_day' => $messages_per_day,
            ],

            'sources' => [
                'distribution' => $bySource,
            ],

            'chunks' => [
                'total' => $totalChunks,           // ✅ total
                'by_source' => $bySource,          // ✅ stats par source
                'items' => $chunkItems,            // ✅ seulement 20 derniers
            ],

            'settings' => $settings,
        ]);
    }*/
    private function getGoogleFaviconSecure(
        string $url,
        int $size = 64,
        bool $removeWww = true
    ): ?string {

        // Tailles autorisées par Google
        $allowedSizes = [16, 32, 48, 64, 128, 256];

        if (!in_array($size, $allowedSizes, true)) {
            $size = 64; // fallback sécurisé
        }

        // Nettoyage de l'URL
        $url = trim($url);

        // Ajouter un schéma si absent (obligatoire pour parse_url)
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if (empty($parts['host'])) {
            return null;
        }

        $domain = strtolower($parts['host']);

        // Supprimer www. si demandé
        if ($removeWww) {
            $domain = preg_replace('/^www\./i', '', $domain);
        }

        // Validation stricte du domaine
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        // Construction de l'URL finale
        return sprintf(
            'https://www.google.com/s2/favicons?sz=%d&domain=%s',
            $size,
            $domain
        );
    }


}
