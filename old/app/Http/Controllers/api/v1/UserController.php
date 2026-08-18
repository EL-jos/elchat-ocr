<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\UserVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request, string $site)
    {
        $site = Site::find($site);

        if (!$site) {
            return $this->errorResponse(
                'Site not found.',
                'SITE_NOT_FOUND',
                404
            );
        }

        if ($response = $this->authorizeSite($site)) {
            return $response;
        }

        $page  = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 20);

        $search          = $request->get('search');
        $verified        = $request->get('verified');
        $inactiveDays    = $request->get('inactive_days');
        $hasUnanswered   = $request->get('has_unanswered');
        $sort            = $request->get('sort', 'last_seen_at');
        $order           = $request->get('order', 'desc');

        /*
        |--------------------------------------------------------------------------
        | Base Query
        |--------------------------------------------------------------------------
        */

        $query = User::query()
            ->select('users.*')
            ->join('site_user', function ($join) use ($site) {
                $join->on('users.id', '=', 'site_user.user_id')
                    ->where('site_user.site_id', $site->id);
            });

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'like', "%$search%")
                    ->orWhere('lastname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Verified filter
        |--------------------------------------------------------------------------
        */

        if (!is_null($verified)) {
            $query->where('is_verified', filter_var($verified, FILTER_VALIDATE_BOOLEAN));
        }

        /*
        |--------------------------------------------------------------------------
        | Inactive filter
        |--------------------------------------------------------------------------
        */

        if ($inactiveDays) {
            $query->where('site_user.last_seen_at', '<=', now()->subDays($inactiveDays));
        }

        /*
        |--------------------------------------------------------------------------
        | With Stats (subqueries)
        |--------------------------------------------------------------------------
        */

        $query->addSelect([
            'first_seen_at' => DB::raw('site_user.first_seen_at'),
            'last_seen_at'  => DB::raw('site_user.last_seen_at'),
        ]);

        $query->withCount([
            'conversations as conversations_count' => function ($q) use ($site) {
                $q->where('site_id', $site->id);
            },
            'messages as messages_count' => function ($q) use ($site) {
                $q->whereHas('conversation', function ($qq) use ($site) {
                    $qq->where('site_id', $site->id);
                });
            }
        ]);

        /*
        |--------------------------------------------------------------------------
        | Unanswered Questions Count
        |--------------------------------------------------------------------------
        */

        $query->addSelect([
            'unanswered_questions_count' => UnansweredQuestion::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('site_id', DB::raw("'{$site->id}'"))
        ]);

        /*
        |--------------------------------------------------------------------------
        | Sort
        |--------------------------------------------------------------------------
        */

        $allowedSorts = ['last_seen_at', 'first_seen_at', 'created_at'];

        if (!in_array($sort, $allowedSorts)) {
            $sort = 'last_seen_at';
        }

        $query->orderBy("site_user.$sort", $order);

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Transform Data
        |--------------------------------------------------------------------------
        */

        $data = $paginator->getCollection()->map(function ($user) use ($site) {

            $lastMessage = Message::where('user_id', $user->id)
                ->whereHas('conversation', fn($q) => $q->where('site_id', $site->id))
                ->latest('created_at')
                ->first();

            return [
                'id'         => $user->id,
                'firstname'  => $user->firstname,
                'lastname'   => $user->lastname,
                'email'      => $user->email,
                'is_verified'=> (bool) $user->is_verified,
                'created_at' => $user->created_at,

                'stats' => [
                    'first_seen_at'             => $user->first_seen_at,
                    'last_seen_at'              => $user->last_seen_at,
                    'conversations_count'       => $user->conversations_count ?? 0,
                    'messages_count'            => $user->messages_count ?? 0,
                    'unanswered_questions_count'=> 0, // à adapter si logique par user
                    'last_message_preview'      => $lastMessage?->content
                        ? substr($lastMessage->content, 0, 80)
                        : null,
                    'last_message_at'           => $lastMessage?->created_at,
                ]
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        $summaryQuery = $site->users();

        $summary = [
            'total_users'        => $summaryQuery->count(),
            'verified_users'     => $summaryQuery->where('is_verified', 1)->count(),
            'unverified_users'   => $summaryQuery->where('is_verified', 0)->count(),
            'inactive_30_days'   => $site->users()
                ->wherePivot('last_seen_at', '<=', now()->subDays(30))
                ->count(),
            'users_with_unanswered' => 0, // à adapter selon logique réelle
        ];

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page'  => $paginator->perPage(),
            ],
            'summary' => $summary
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function show(string $userId, string $siteId)
    {
        /*
        |--------------------------------------------------------------------------
        | Charger le site + sécurisation
        |--------------------------------------------------------------------------
        */

        $site = Site::with('account')->findOrFail($siteId);
        $this->authorizeSite($site);

        /*
        |--------------------------------------------------------------------------
        | Charger le user UNIQUEMENT s’il appartient au site (ManyToMany)
        |--------------------------------------------------------------------------
        */

        $user = User::where('id', $userId)
            ->whereHas('sites', function ($q) use ($siteId) {
                $q->where('sites.id', $siteId);
            })
            ->with(['sites' => function ($q) use ($siteId) {
                $q->where('sites.id', $siteId);
            }])
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Pivot (ManyToMany)
        |--------------------------------------------------------------------------
        */

        $pivot = $user->sites->first()?->pivot;

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        $conversations = Conversation::where('site_id', $siteId)
            ->where('user_id', $userId)
            ->withCount('messages')
            ->withMax('messages', 'created_at') // ⚡ évite N+1
            ->get();

        $conversationsCount = $conversations->count();
        $messagesCount = $conversations->sum('messages_count');

        /*
        |--------------------------------------------------------------------------
        | Verification
        |--------------------------------------------------------------------------
        */

        $verification = UserVerification::where('user_id', $userId)
            ->latest()
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Unanswered Questions (site level)
        |--------------------------------------------------------------------------
        */

        $unansweredQuestions = UnansweredQuestion::where('site_id', $siteId)
            ->latest()
            ->get(['id', 'site_id', 'question', 'created_at']);

        /*
        |--------------------------------------------------------------------------
        | Format Conversations
        |--------------------------------------------------------------------------
        */

        $formattedConversations = $conversations->map(function ($conv) {
            return [
                'id'              => $conv->id,
                'site_id'         => $conv->site_id,
                'created_at'      => $conv->created_at,
                'messages_count'  => $conv->messages_count,
                'last_message_at' => $conv->messages_max_created_at,
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'id'          => $user->id,
            'firstname'   => $user->firstname,
            'lastname'    => $user->lastname,
            'email'       => $user->email,
            'is_verified' => (bool) $user->is_verified,
            'created_at'  => $user->created_at,

            'verification' => $verification ? [
                'type'       => $verification->type,
                'expires_at' => $verification->expires_at,
                'used_at'    => $verification->used_at,
                'attempts'   => $verification->attempts,
            ] : null,

            'stats' => [
                'first_seen_at'       => $pivot?->first_seen_at,
                'last_seen_at'        => $pivot?->last_seen_at,
                'conversations_count' => $conversationsCount,
                'messages_count'      => $messagesCount,
            ],

            'conversations'        => $formattedConversations,
            'unanswered_questions' => $unansweredQuestions,
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
}
