<?php

namespace App\Services\mcp;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;

/**
 * Reprend votre logique transformVisitorToUser existante. Appelée par
 * MCPActionGateService dès qu'un ToolResult révèle une identité (email de
 * commande, création de compte...) pendant que la conversation est encore
 * anonyme.
 */
class VisitorIdentityService
{
    public function resolveFromIdentity(Site $site, ?Visitor $visitor, array $identity): ?User
    {
        if (!$visitor || $visitor->user_id) {
            return $visitor?->user; // déjà lié, ou pas de visiteur à transformer
        }

        $email = $identity['email'] ?? null;
        if (!$email) {
            return null;
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            $visitorRole = Role::where('name', 'visitor')->first();

            $user = User::create([
                'role_id' => $visitorRole?->id,
                'firstname' => $identity['firstname'] ?? null,
                'lastname' => $identity['lastname'] ?? null,
                'email' => $email,
                'phone' => $identity['phone'] ?? null,
                'is_verified' => false,
            ]);
        }

        $this->transformVisitorToUser($visitor, $user);

        return $user;
    }

    /**
     * Reproduction fidèle de votre méthode existante.
     */
    protected function transformVisitorToUser(Visitor $visitor, User $user): void
    {
        $visitor->user_id = $user->id;
        $visitor->save();

        Conversation::where('visitor_id', $visitor->id)
            ->update(['user_id' => $user->id]);

        Message::whereIn('conversation_id', function ($q) use ($visitor) {
            $q->select('id')
                ->from('conversations')
                ->where('visitor_id', $visitor->id);
        })->update(['user_id' => $user->id]);

        $this->attachUserToSiteIfNeeded($user, $visitor->site_id);
    }

    private function attachUserToSiteIfNeeded(User $user, ?string $siteId): void
    {
        if (!$siteId) {
            return;
        }

        $now = now();

        if (!$user->sites()->where('site_id', $siteId)->exists()) {
            $user->sites()->attach($siteId, ['first_seen_at' => $now, 'last_seen_at' => $now]);
        } else {
            $user->sites()->updateExistingPivot($siteId, ['last_seen_at' => $now]);
        }
    }
}
