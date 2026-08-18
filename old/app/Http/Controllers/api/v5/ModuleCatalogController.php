<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Controller;
use App\Services\payment\ModuleCatalogService;
use App\Traits\JwtAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleCatalogController extends Controller
{
    use JwtAuth;

    public function __construct(private ModuleCatalogService $catalog) {}

    /**
     * GET /api/modules/catalog
     * Retourne le catalogue de modules avec le statut de chacun pour le compte connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireJwtAccount($request);
        $cycle   = $request->input('billing_cycle', $account->subscription?->billing_cycle ?? 'monthly');

        $catalog = $this->catalog->catalogForAccount($account, $cycle);

        return response()->json([
            'modules'       => $catalog['modules'],
            'trial_active'  => $catalog['trial_active'],   // 🆕
            'billing_cycle' => $cycle,
        ]);
    }

    /**
     * GET /api/subscription/summary
     * Résumé "Core 29€ + Community 19€ = 48€/mois"
     */
    public function summary(Request $request): JsonResponse
    {
        $account      = $this->requireJwtAccount($request);
        $subscription = $account->subscription;

        if (!$subscription) {
            return response()->json(['subscription' => null]);
        }

        return response()->json([
            'subscription' => [
                'status'               => $subscription->status,
                'billing_cycle'        => $subscription->billing_cycle,
                'is_trialing'          => $subscription->isTrialing(),
                'trial_ends_at'        => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end'   => $subscription->current_period_end?->toIso8601String(),
                'summary'              => $this->catalog->subscriptionSummary($subscription),
            ],
        ]);
    }
}
