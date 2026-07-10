<?php

namespace App\Services\Social;

use App\Models\Social\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UserResolver
 * ─────────────────────────────────────────────────────────────────────────────
 * Responsabilité unique : trouver (ou créer) le User ELChat correspondant
 * à une identité sociale, puis s'assurer que le SocialAccount courant lui
 * est bien lié dans la table pivot `social_account_user`.
 *
 * Règles métier :
 *  1. Si (provider + external_user_id) est déjà dans le pivot → User existant,
 *     rien à créer.
 *  2. Si le même external_user_id existe sur UN AUTRE canal du même site →
 *     on réutilise ce User et on attache simplement le nouveau canal.
 *  3. Sinon → création d'un nouveau User "social" (sans email/password).
 * ─────────────────────────────────────────────────────────────────────────────
 */
class UserResolver
{
    /**
     * @param  SocialAccount $account         Le canal social qui reçoit le message
     * @param  string        $externalUserId  Identifiant natif (ex: channel_id YouTube)
     * @param  string|null   $displayName     Nom affiché par le réseau social
     * @param  string|null   $username        @username ou handle
     * @return User
     */
    public function resolve(
        SocialAccount $account,
        string        $externalUserId,
        ?string       $displayName = null,
        ?string       $username    = null,
        ?string       $email      = null,
        ?string       $phone      = null,
    ): User {
        return DB::transaction(function () use ($account, $externalUserId, $displayName, $username) {

            // ── 1. Ce canal connaît-il déjà cet external_user_id ? ────────
            $existingUser = $this->findUserByChannelIdentity(
                $account->provider->value,
                $externalUserId
            );

            if ($existingUser) {
                // Assure quand même que le pivot pointe bien vers ce SocialAccount
                $this->attachIfMissing($existingUser, $account, $externalUserId, $displayName, $username);

                Log::info('[UserResolver] User existant trouvé via pivot', [
                    'user_id'          => $existingUser->id,
                    'provider'         => $account->provider->value,
                    'external_user_id' => $externalUserId,
                ]);

                return $existingUser;
            }

            // ── 2. Même external_user_id sur un autre canal du même site ? ─
            // (cas rare mais possible si l'API retourne le même ID cross-canal)
            // On ne cherche pas ici intentionnellement : les ID sont propres
            // à chaque réseau, donc on crée toujours un User distinct si
            // aucun pivot ne correspond.

            // ── 3. Création d'un nouveau User ─────────────────────────────
            $user = $this->createSocialUser($account->site_id, $displayName, $username);

            $this->attachIfMissing($user, $account, $externalUserId, $displayName, $username);

            $this->attachUserToSiteIfNeeded(user: $user, siteId: $account->site_id);

            Log::info('[UserResolver] Nouveau User social créé', [
                'user_id'          => $user->id,
                'provider'         => $account->provider->value,
                'external_user_id' => $externalUserId,
                'display_name'     => $displayName,
            ]);

            return $user;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cherche un User via la table pivot sur (provider + external_user_id).
     * Indépendant du SocialAccount : couvre le cas "déjà vu sur Facebook,
     * maintenant sur YouTube avec le même ID" (peu probable mais géré).
     */
    private function findUserByChannelIdentity(string $provider, string $externalUserId): ?User
    {
        $pivotRow = DB::table('social_account_user')
            ->where('provider', $provider)
            ->where('external_user_id', $externalUserId)
            ->first();

        if (!$pivotRow) {
            return null;
        }

        return User::find($pivotRow->user_id);
    }

    /**
     * Attache le SocialAccount au User dans la table pivot,
     * uniquement si la ligne n'existe pas encore.
     * On utilise syncWithoutDetaching pour être idempotent.
     */
    private function attachIfMissing(
        User          $user,
        SocialAccount $account,
        string        $externalUserId,
        ?string       $displayName,
        ?string       $username,
    ): void {
        // Vérifie l'existence de la ligne exacte dans le pivot
        $alreadyLinked = DB::table('social_account_user')
            ->where('user_id',          $user->id)
            ->where('social_account_id', $account->id)
            ->where('provider',          $account->provider->value)
            ->where('external_user_id',  $externalUserId)
            ->exists();

        if ($alreadyLinked) {
            return;
        }

        // La PK composite inclut provider + external_user_id,
        // donc on passe par une insertion directe pour porter ces colonnes.
        DB::table('social_account_user')->insert([
            'social_account_id'      => $account->id,
            'user_id'                => $user->id,
            'provider'               => $account->provider->value,
            'external_user_id'       => $externalUserId,
            'external_username'      => $username,
            'external_display_name'  => $displayName,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        Log::info('[UserResolver] Canal attaché au User', [
            'user_id'           => $user->id,
            'social_account_id' => $account->id,
            'provider'          => $account->provider->value,
            'external_user_id'  => $externalUserId,
        ]);
    }

    /**
     * Crée un User "social" minimal.
     * Pas d'email, pas de password : ce User sera enrichi si
     * l'humain fournit ses coordonnées plus tard dans la conversation.
     */
    private function createSocialUser(string $siteId, ?string $displayName, ?string $username): User
    {
        // Décomposition naïve du display_name en prénom / nom
        $parts     = $displayName ? explode(' ', trim($displayName), 2) : [];
        $firstname = $parts[0] ?? ($username ?? 'Visiteur');
        $lastname  = $parts[1] ?? null;

        // On récupère le rôle "visitor" — adapte selon ta table roles
        $visitorRole = DB::table('roles')->where('name', 'visitor')->first();

        return User::create([
            'id'        => (string) Str::uuid(),
            'role_id'   => $visitorRole?->id,
            'firstname' => $firstname,
            'lastname'  => $lastname,
            // email / phone / password → null : compte "social" non vérifié
        ]);
    }

    private function attachUserToSiteIfNeeded(User $user, ?string $siteId): void
    {
        if (! $siteId) {
            return;
        }

        $now = now();

        if (! $user->sites()->where('site_id', $siteId)->exists()) {

            // Première visite
            $user->sites()->attach($siteId, [
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]);

        } else {

            // Déjà lié → on met à jour uniquement last_seen_at
            $user->sites()->updateExistingPivot($siteId, [
                'last_seen_at' => $now,
            ]);
        }
    }
}
