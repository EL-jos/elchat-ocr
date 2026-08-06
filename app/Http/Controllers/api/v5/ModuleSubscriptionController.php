<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Controller;
use App\Services\payment\SubscriptionOrchestrator;
use App\Traits\JwtAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ModuleSubscriptionController extends Controller
{
    use JwtAuth;

    public function __construct(private SubscriptionOrchestrator $orchestrator) {}

    /**
     * POST /api/modules/{slug}/activate
     * { "tier": "basic" }
     */
    /**
     * POST /api/modules/{slug}/trial
     * Essai gratuit — remplace l'ancien activate().
     */
    public function startTrial(Request $request, string $slug): JsonResponse
    {
        $request->validate(['tier' => ['nullable', 'string']]);
        $account = $this->requireJwtAccount($request);

        try {
            $subscription = $this->orchestrator->startModuleTrial($account, $slug, $request->get('tier'));

            return response()->json([
                'message'      => "Essai du module « {$slug} » activé.",
                'subscription' => $subscription->fresh('items.module', 'items.moduleTier'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/modules/{slug}/purchase
     * Paiement PayPal réel — retourne approval_url si une popup est nécessaire.
     */
    public function purchase(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'tier'          => ['nullable', 'string'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
        ]);
        $account = $this->requireJwtAccount($request);

        try {
            $result = $this->orchestrator->purchaseModule(
                $account,
                $slug,
                $request->get('tier'),
                $request->get('billing_cycle')
            );

            return response()->json([
                'message'      => $result['approval_url']
                    ? 'Redirection PayPal requise.'
                    : "Module « {$slug} » activé et facturé.",
                'subscription' => $result['subscription'],
                'approval_url' => $result['approval_url'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/modules/{slug}/deactivate
     * Effet différé — accès conservé jusqu'à la fin de la période payée.
     */
    public function deactivate(Request $request, string $slug): JsonResponse
    {
        $account = $this->requireJwtAccount($request);

        try {
            $subscription = $this->orchestrator->deactivateModule($account, $slug);

            return response()->json([
                'message'      => "Module « {$slug} » sera désactivé à la fin de votre période en cours.",
                'subscription' => $subscription->fresh('items.module', 'items.moduleTier'),
            ]);
        } catch (\Throwable $e) {
            Log::error('ModuleSubscriptionController::deactivate failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/modules/{slug}/upgrade
     * { "tier": "pro" }
     * Upgrade en place — la ligne existante change de tier, jamais de nouvel item.
     */
    public function upgrade(Request $request, string $slug): JsonResponse
    {
        $request->validate(['tier' => ['required', 'string']]);

        $account = $this->requireJwtAccount($request);

        try {
            $subscription = $this->orchestrator->changeModuleTier($account, $slug, $request->tier);

            return response()->json([
                'message'      => "Module « {$slug} » mis à niveau vers « {$request->tier} ».",
                'subscription' => $subscription->fresh('items.module', 'items.moduleTier'),
            ]);
        } catch (\Throwable $e) {
            Log::error('ModuleSubscriptionController::upgrade failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/subscription/coupon
     * { "code": "PROMO2026" }
     */
    public function applyCoupon(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $account = $this->requireJwtAccount($request);

        try {
            $subscription = $this->orchestrator->applyCoupon($account, $request->code);

            return response()->json([
                'message'      => 'Code promo appliqué avec succès.',
                'subscription' => $subscription->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/subscription/trial/convert
     * { "modules": ["community", "business"], "billing_cycle": "monthly" }
     * Appelé depuis l'écran "Choisissez les modules à conserver" en fin de trial.
     */
    public function convertTrial(Request $request): JsonResponse
    {
        $request->validate([
            'modules'       => ['required', 'array'],
            'modules.*'     => ['string'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $account = $this->requireJwtAccount($request);

        try {
            $subscription = $this->orchestrator->convertTrialToPaid(
                $account,
                $request->modules,
                $request->billing_cycle
            );

            return response()->json([
                'message'      => 'Abonnement ELChat activé.',
                'subscription' => $subscription->fresh('items.module', 'items.moduleTier'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
