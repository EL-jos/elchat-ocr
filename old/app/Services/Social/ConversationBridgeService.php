<?php

namespace App\Services\Social;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialMessage;
use App\Models\User;
use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ConversationBridgeService
 * ─────────────────────────────────────────────────────────────────────────────
 * Crée (ou retrouve) la Conversation ELChat associée à une SocialConversation,
 * et insère le Message RAG correspondant au premier commentaire.
 *
 * Logique de reformulation :
 *   - On appelle le LLM pour transformer (titre vidéo + commentaire brut)
 *     en UNE question naturelle, exploitable par le moteur RAG d'ELChat.
 *   - Le Message ELChat a role='user' et pointe vers cette question.
 *   - La SocialMessage reçoit également cette question reformulée comme content.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class ConversationBridgeService
{
    public function __construct(
        private readonly LLMService $llm,
    ) {}

    /**
     * Point d'entrée principal.
     *
     * @param  SocialConversation $socialConv     Conversation sociale (nouvelle ou existante)
     * @param  SocialMessage      $socialMessage  Le SocialMessage entrant déjà persisté
     * @param  User               $user           User ELChat résolu
     * @param  bool               $isNewConversation  True uniquement sur wasRecentlyCreated
     * @param  array              $videoContext   ['title' => '...', 'description' => '...'] (optionnel)
     */
    public function bridge(
        SocialConversation $socialConv,
        SocialMessage      $socialMessage,
        User               $user,
        bool               $isNewConversation,
        array              $videoContext = [],
    ): void {

        // ✅ Garde absolue : on ne crée jamais de Conversation ELChat
        // ni de message RAG à partir d'un message sortant (echo IA).
        // Cette vérification est faite ici et non uniquement dans les
        // parsers, pour être robuste contre toute future régression.
        
        if ($socialMessage->direction->value === 'outgoing') {
            Log::warning('[ConversationBridge] Tentative de bridge sur un message sortant bloquée', [
                'social_message_id' => $socialMessage->id,
                'direction'         => $socialMessage->direction->value,
            ]);
            return;
        }

        // ── 1. Trouver ou créer la Conversation ELChat ────────────────────
        $conversation = $this->resolveElchatConversation($socialConv, $user);

        // ── 2. Si c'est le premier commentaire de la conv → reformuler + persister
        if ($isNewConversation) {
            $this->createFirstRagMessage(
                $conversation,
                $socialMessage,
                $user,
                $videoContext,
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retrouve ou crée la Conversation ELChat liée à la SocialConversation.
     * On stocke l'ID de la social_conversation dans les métadonnées pour le lien.
     */
    private function resolveElchatConversation(
        SocialConversation $socialConv,
        User               $user,
    ): Conversation {

        // Recherche par user_id + social_conversation_id stocké en metadata
        $existing = Conversation::where('user_id', $user->id)
            ->where('site_id', $socialConv->site_id)
            // On utilise un JSON path pour retrouver la conv ELChat liée
            ->whereJsonContains('metadata->social_conversation_id', $socialConv->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Conversation::create([
            'id' => (string) Str::uuid(),
            'site_id' => $socialConv->site_id,
            'user_id' => $user->id,
            'status'  => 'open',
            'metadata' => [
                'social_conversation_id' => $socialConv->id,
                'provider'               => $socialConv->provider,
                'context_type'           => $socialConv->context_type,
                'context_id'             => $socialConv->context_id,
                'external_user_id'       => $socialConv->external_user_id,
            ],
        ]);
    }

    /**
     * Reformule le premier commentaire en question RAG via le LLM,
     * puis crée le Message ELChat et met à jour le content du SocialMessage.
     */
    private function createFirstRagMessage(
        Conversation  $conversation,
        SocialMessage $socialMessage,
        User          $user,
        array         $videoContext,
    ): void {
        $rawComment  = $socialMessage->content;
        $videoTitle  = $videoContext['title']       ?? null;
        $videoDesc   = $videoContext['description'] ?? null;

        // ── Reformulation LLM ─────────────────────────────────────────────
        //$question = $this->reformulateAsQuestion($rawComment, $videoTitle, $videoDesc);
        $question = $rawComment;

        Log::info('[ConversationBridge] Question RAG générée', [
            'social_message_id' => $socialMessage->id,
            'conversation_id'   => $conversation->id,
            'original'          => $rawComment,
            'reformulated'      => $question,
        ]);

        // ── Message ELChat (table messages) ──────────────────────────────
        Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id'         => $user->id,
            'content'         => $question,
            'role'            => 'user',
            // entities peut être enrichi plus tard par le pipeline RAG
        ]);

        // ── Mise à jour du SocialMessage avec le contenu reformulé ────────
        // On conserve le commentaire brut dans metadata->raw_comment
        $socialMessage->update([
            'content'  => $question,
            'metadata' => array_merge($socialMessage->metadata ?? [], [
                'raw_comment'   => $rawComment,
                'rag_question'  => $question,
                'reformulated'  => true,
            ]),
        ]);
    }

    /**
     * Appelle le LLM pour transformer un commentaire YouTube brut
     * en une question conversationnelle exploitable par le RAG d'ELChat.
     *
     * En cas d'échec LLM, on retourne le commentaire original (fallback sûr).
     */
    private function reformulateAsQuestion(
        string  $comment,
        ?string $videoTitle,
        ?string $videoDescription,
    ): string {
        // ── Contexte vidéo optionnel ──────────────────────────────────────
        $contextLines = [];
        if ($videoTitle) {
            $contextLines[] = "Titre de la vidéo : {$videoTitle}";
        }
        if ($videoDescription) {
            $shortDesc      = mb_substr($videoDescription, 0, 400);
            $contextLines[] = "Description (extrait) : {$shortDesc}";
        }
        $contextBlock = $contextLines
            ? implode("\n", $contextLines) . "\n\n"
            : '';

        // ── Prompt orienté intention commerciale / génération de lead ─────
        //
        // Principe : on se met dans la tête du visiteur et on exprime
        // la demande concrète (contact, devis, achat, conseil…) qu'il
        // aurait formulée s'il avait été plus explicite, en tenant compte
        // du sujet de la vidéo. ELChat doit pouvoir y répondre directement.
        //
        // Ce qu'on veut éviter :
        //   ❌ "Qu'est-ce qui vous a particulièrement plu ?" (question vers le visiteur)
        //   ❌ "Super vidéo !" (reformulation du commentaire brut)
        //
        // Ce qu'on veut obtenir :
        //   ✅ "Je voudrais en savoir plus sur vos luminaires pour villa."
        //   ✅ "Est-ce que vous proposez ce type d'installation à La Réunion ?"
        //   ✅ "Comment puis-je vous contacter pour obtenir un devis ?"

        $systemPrompt = <<<SYSTEM
Tu es un expert en génération de leads pour une entreprise.

Contexte : un prospect a interagi avec le contenu d'une entreprise sur une plateforme digitale (réseau social, messagerie, email, vidéo, etc.).
Ton rôle : te mettre dans la peau de ce prospect et décoder l'intention réelle derrière ce message et de la reformuler en une demande concrète, à la première personne, que le prospect aurait pu écrire s'il avait été plus explicite.

La demande générée doit :
- Être écrite à la PREMIÈRE PERSONNE du prospect (ex: "Je voudrais…", "Comment puis-je…", "Est-ce que vous proposez…", "Je suis intéressé par…").
- Exprimer une intention d'action concrète en lien avec le sujet du contenu : demande de contact, de devis, d'information produit/service, de disponibilité, de tarif, d'accompagnement ou d'achat.
- Être naturelle, directe, et tenir en une seule phrase.
- Être rédigée dans la même langue que le message du prospect.

Règles absolues :
- Réponds UNIQUEMENT avec la demande, sans guillemets, sans explication, sans préambule.
- Ne pose JAMAIS une question adressée AU prospect (ex: "Qu'est-ce qui vous a plu ?").
- Ne reproduis pas le message tel quel.
- Ne génère pas une opinion ou une réaction — génère uniquement une DEMANDE ou une QUESTION de contact, d'achat ou de conseil.
- Si le message est déjà une demande explicite et bien formée, retourne-la corrigée sans la dénaturer.
SYSTEM;

        $userPrompt = <<<USER
{$contextBlock}Message du prospect : "{$comment}"

Demande concrète du prospect :
USER;

        try {
            $question = $this->llm->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ], [
                'max_tokens'  => 80,
                'temperature' => 0.4,
            ]);

            return trim($question, " \t\n\r\"'");

        } catch (\Throwable $e) {
            Log::warning('[ConversationBridge] LLM reformulation échouée, fallback sur commentaire brut', [
                'error'   => $e->getMessage(),
                'comment' => $comment,
            ]);

            return $comment;
        }
    }
}
