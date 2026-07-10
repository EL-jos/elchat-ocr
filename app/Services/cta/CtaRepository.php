<?php

namespace App\Services\cta;

use App\Models\ChatbotCta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CtaRepository
{
    public function paginateForSite(
        string $siteId,
        int $perPage = 10
    ) {
        return ChatbotCta::query()
            ->where('site_id', $siteId)
            ->with('rules')
            ->orderBy('position')
            ->paginate($perPage);
    }
    
    /**
     * Récupère tous les CTA actifs pour un site donné,
     * avec leurs règles, triés par position.
     *
     * @param string $siteId
     * @return Collection<ChatbotCta>
     */
    public function getActiveForSite(string $siteId): Collection
    {
        return ChatbotCta::with(['rules'])
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->orderBy('position', 'asc') // ordre de priorité
            ->get();
    }

    /**
     * Récupère un CTA spécifique par ID avec ses règles.
     *
     * @param string $ctaId
     * @return ChatbotCta|null
     */
    public function findById(string $ctaId): ?ChatbotCta
    {
        return ChatbotCta::with(['rules'])
            ->where('id', $ctaId)
            ->first();
    }

    /**
     * Crée un nouveau CTA et retourne le model complet avec règles chargées.
     *
     * @param array $data
     * @return ChatbotCta
     */
    public function create(array $data): ChatbotCta
    {
        // 1️⃣ Extraire les rules si elles existent
        $rulesData = $data['rules'] ?? [];
        unset($data['rules']); // ❌ important, sinon SQL error

        $cta = null;

        DB::transaction(function() use ($data, &$cta, $rulesData) {

            // 2️⃣ Créer le CTA
            $cta = ChatbotCta::create($data);

            // 3️⃣ Créer les règles liées
            foreach ($rulesData as $rule) {
                $cta->rules()->create($rule);
            }

        });

        // 4️⃣ Retourner le CTA avec ses règles chargées
        return $this->findById($cta->id);
    }

    public function update(ChatbotCta $cta, array $data): ChatbotCta
    {
        $rulesData = $data['rules'] ?? null;
        unset($data['rules']); // On ne touche pas à la table principale

        DB::transaction(function() use ($data, &$cta, $rulesData) {

            $cta->update($data);

            // Supprimer les anciennes règles
            $cta->rules()->delete();

            // Ajouter les nouvelles règles
            foreach ($rulesData as $rule) {
                $cta->rules()->create($rule);
            }

        });

        return $this->findById($cta->id);
    }

    /**
     * Supprime un CTA et ses règles associées.
     *
     * @param string $ctaId
     * @return bool
     */
    public function delete(string $ctaId): bool
    {
        /**
         * @var ChatbotCta $cta
         */
        $cta = $this->findById($ctaId);
        if (!$cta) {
            return false;
        }

        $cta->rules()->delete();

        return $cta->delete();
    }

    /**
     * Désactive un CTA (soft toggle) pour ne plus l'afficher.
     *
     * @param string $ctaId
     * @return ChatbotCta|null
     */
    public function deactivate(string $ctaId): ?ChatbotCta
    {
        $cta = $this->findById($ctaId);
        if (!$cta) {
            return null;
        }

        $cta->is_active = false;
        $cta->save();

        return $cta;
    }

    /**
     * Active un CTA qui était désactivé.
     *
     * @param string $ctaId
     * @return ChatbotCta|null
     */
    public function activate(string $ctaId): ?ChatbotCta
    {
        $cta = $this->findById($ctaId);
        if (!$cta) {
            return null;
        }

        $cta->is_active = true;
        $cta->save();

        return $cta;
    }

    public function getForSite(string $siteId)
    {
        return ChatbotCta::with('rules')
            ->where('site_id', $siteId)
            ->orderBy('position')
            ->get();
    }

    /**
     * Supprime tous les CTA d'un site donné, ainsi que leurs règles associées.
     *
     * @param string $siteId
     * @return int Nombre de CTA supprimés
     * @throws \Exception
     */
    public function deleteAllForSite(string $siteId): int
    {
        return DB::transaction(function () use ($siteId) {

            // Récupérer tous les CTA du site
            $ctas = ChatbotCta::with('rules')->where('site_id', $siteId)->get();

            $count = $ctas->count();

            foreach ($ctas as $cta) {
                // Supprimer les règles associées
                $cta->rules()->delete();

                // Supprimer le CTA lui-même
                $cta->delete();
            }

            return $count;
        });
    }

    public function deleteManyForSite(string $siteId, array $ids): int
    {
        return ChatbotCta::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $ids)
            ->delete();
    }
}
