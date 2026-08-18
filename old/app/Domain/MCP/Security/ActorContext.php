<?php

namespace App\Domain\MCP\Security;

use App\Models\Conversation;

/**
 * Qui parle réellement dans cette conversation : un visiteur anonyme, un
 * client identifié, ou un membre de l'équipe (admin). Dérivé de la
 * conversation existante — aucune donnée nouvelle à saisir.
 */
final readonly class ActorContext
{
    public function __construct(
        public string $ownerType,  // 'visitor' | 'user' — clé de propriété panier/wishlist
        public string $ownerId,
        public bool $isAdmin,
    ) {
    }

    public static function fromConversation(Conversation $conversation): self
    {
        if ($conversation->user_id) {
            // ⚠️ Suppose une relation Conversation::user() belongsTo(User).
            $user = $conversation->user;
            return new self(ownerType: 'user', ownerId: $conversation->user_id, isAdmin: (bool) $user?->isAdmin());
        }

        return new self(ownerType: 'visitor', ownerId: $conversation->visitor_id, isAdmin: false);
    }

    /** Ce que voit le PermissionEngine pour filtrer les outils autorisés. */
    public function scope(): string
    {
        return $this->isAdmin ? 'admin' : 'visitor';
    }
}
