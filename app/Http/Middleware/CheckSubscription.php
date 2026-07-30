<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckSubscription middleware — version JWT
 *
 * AVANT : Auth::user()->account->subscription
 * APRÈS : $request->jwt_account->subscription
 *         (jwt_account est injecté par JwtAuthMiddleware)
 *
 * Ce middleware doit être placé APRÈS JwtAuthMiddleware dans la pile.
 */
class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        // JwtAuthMiddleware doit avoir tourné avant
        // Si jwt_user est null → laisser JwtAuthMiddleware gérer
        $user    = $request->input('jwt_user');
        $account = $request->input('jwt_account');

        if (!$user) {
            return $next($request); // JwtAuthMiddleware redirige déjà
        }

        // Ignorer les endpoints de subscription eux-mêmes
        if ($request->is('api/subscription*') || $request->is('api/plans*')) {
            return $next($request);
        }

        if (!$account) {
            return $next($request); // Pas de compte → laisser passer (inscription en cours?)
        }

        $subscription = $account->subscription;

        if (!$subscription) {
            return $this->redirectToTarifs($request, 'no_subscription');
        }

        if ($subscription->isUsable()) {
            $response = $next($request);
            $this->appendSubscriptionHeaders($response, $subscription);
            return $response;
        }

        if ($subscription->trialExpired()) {
            return $this->redirectToTarifs($request, 'trial_expired');
        }

        if ($subscription->isPastDue()) {
            return $this->redirectToTarifs($request, 'past_due');
        }

        if ($subscription->isCanceled()) {
            return $this->redirectToTarifs($request, 'canceled');
        }

        return $this->redirectToTarifs($request, 'inactive');
    }

    private function redirectToTarifs(Request $request, string $reason): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error'    => 'subscription_required',
                'reason'   => $reason,
                'redirect' => url('/tarifs?reason=' . $reason),
            ], 402);
        }

        return redirect(url('/tarifs?reason=' . $reason));
    }

    private function appendSubscriptionHeaders(Response $response, $subscription): void
    {
        $response->headers->set('X-Subscription-Status', $subscription->status);
        $response->headers->set('X-Plan-Slug', $subscription->plan?->slug ?? 'starter');

        if ($subscription->isTrialing()) {
            $response->headers->set('X-Trial-Days-Remaining', $subscription->trialDaysRemaining());
        }
    }
}
