<?php

namespace App\Listeners;

use App\Models\Payment\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    /**
     * Seul le owner du compte peut gérer l'abonnement (activer/désactiver des modules).
     * À étendre si vous introduisez des rôles multi-utilisateurs par compte.
     */
    public function manage(User $user, Subscription $subscription): bool
    {
        return $subscription->account->owner_user_id === $user->id;
    }
}
