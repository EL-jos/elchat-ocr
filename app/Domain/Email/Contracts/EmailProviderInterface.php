<?php

namespace App\Domain\Email\Contracts;

use App\Domain\Email\DTO\{EmailEvent, EmailSendResult, InboundEmailMessage, OutboundEmail};
use Illuminate\Http\Request;

/**
 * Abstraction générique — AUCUN appelant (Agent Sales Hunter, campagnes,
 * moteur de prospection) ne doit connaître SES, Mailgun, Postmark ou tout
 * autre fournisseur. Toute logique propre à un fournisseur (signature de
 * webhook, format de payload, SDK) reste encapsulée dans son adaptateur.
 *
 * Ajouter un fournisseur = une nouvelle classe qui implémente cette
 * interface + une entrée dans config('mail-providers.php') — zéro
 * modification ailleurs dans l'application.
 */
interface EmailProviderInterface
{
    /** Identifiant court, ex: 'ses', 'mailgun'. */
    public function key(): string;

    /**
     * Envoie un email. Le statut retourné est TOUJOURS 'accepted' ou
     * 'failed' — jamais 'sent'/'delivered' : seul un événement webhook
     * ultérieur peut confirmer la délivrance réelle.
     */
    public function send(OutboundEmail $email): EmailSendResult;

    // ── Événements (delivered/bounced/complained/rejected/opened/clicked) ──

    public function verifyEventWebhookSignature(Request $request): bool;

    /** @return EmailEvent[] un webhook peut porter plusieurs événements selon le fournisseur */
    public function parseEventWebhook(Request $request): array;

    /**
     * Réponse HTTP à renvoyer immédiatement au fournisseur (ex: SNS exige
     * une confirmation d'abonnement avant de recevoir de vrais événements).
     * Null = rien de spécial à faire, répondre 200 nu.
     */
    public function handleWebhookHandshake(Request $request): ?array;

    // ── Réception (réponse d'un prospect) ──

    public function verifyInboundWebhookSignature(Request $request): bool;

    /** Null si le payload ne contient pas d'email exploitable (ex: notification de service). */
    public function parseInboundWebhook(Request $request): ?InboundEmailMessage;
}
