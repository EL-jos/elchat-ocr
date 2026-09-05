<?php

namespace App\Domain\MCP\Security;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * Qui parle réellement dans cette conversation : un visiteur anonyme, un
 * client identifié, ou un membre de l'équipe (admin). Dérivé de la
 * conversation existante — aucune donnée nouvelle à saisir.
 */
final readonly class ActorContext
{
    public function __construct(
        public string $ownerType,  // 'visitor' | 'user' | 'system' — clé de propriété panier/wishlist
        public string $ownerId,
        public bool $isAdmin,
    ) {}

    public static function fromConversation(Conversation $conversation): self
    {
        if ($conversation->user_id) {
            // ⚠️ Suppose une relation Conversation::user() belongsTo(User).
            $user = $conversation->user;

            return new self(ownerType: 'user', ownerId: $conversation->user_id, isAdmin: (bool) $user?->isAdmin());
        }

        // Une conversation synthétique peut ne référencer ni utilisateur ni
        // visiteur (Sales Hunter, jobs système, imports...). Ne jamais la
        // considérer comme admin par défaut, mais éviter également le
        // TypeError qui empêchait les appels déterministes au CRM.
        if (! $conversation->visitor_id) {
            Log::warning('ActorContext: conversation sans propriétaire explicite', [
                'conversation_id' => $conversation->id,
            ]);

            return new self(ownerType: 'visitor', ownerId: (string) $conversation->id, isAdmin: false);
        }

        return new self(ownerType: 'visitor', ownerId: (string) $conversation->visitor_id, isAdmin: false);
    }

    /**
     * Contexte explicite pour les jobs système qui pilotent un agent sans
     * utilisateur ou visiteur réel (ex: qualification Sales Hunter).
     *
     * L'identifiant de conversation garantit un propriétaire stable pour
     * l'audit, sans attribuer l'action à un utilisateur fictif.
     */
    public static function forSystem(Conversation $conversation): self
    {
        return new self(ownerType: 'system', ownerId: $conversation->id, isAdmin: true);
    }

    /** Ce que voit le PermissionEngine pour filtrer les outils autorisés. */
    public function scope(): string
    {
        return $this->isAdmin ? 'admin' : 'visitor';
    }
}
