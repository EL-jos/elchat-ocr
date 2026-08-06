<?php

namespace App\Payment\Contracts;

use App\Models\Account;
use App\Models\Payment\Coupon;
use App\Models\Payment\Subscription;
use App\Payment\DTO\ModuleLineItem;
use App\Payment\DTO\SubscriptionSnapshot;

/**
 * Contrat unique pour tout provider de paiement (PayPal, Stripe, ...).
 *
 * Aucune classe en dehors de app/Payment/Gateways ne doit connaître les détails
 * d'implémentation d'un provider. L'Orchestrator ne parle QU'à cette interface.
 *
 * Chaque implémentation gère sa propre stratégie interne pour simuler des lignes
 * multiples si le provider ne le supporte pas nativement (cas PayPal — voir
 * PaypalPaymentGateway pour la stratégie de "plan agrégé par montant").
 */
interface PaymentGatewayInterface
{
    /**
     * Crée un abonnement chez le provider avec la composition initiale de modules.
     * Utilisé lors de la 1ère souscription payante (fin de trial ou souscription directe).
     *
     * @param ModuleLineItem[] $lineItems
     */
    public function createSubscription(Account $account, array $lineItems, string $billingCycle): SubscriptionSnapshot;

    /**
     * Met à jour l'abonnement existant pour refléter une nouvelle composition complète
     * de modules (utilisé après tout ajout/retrait/upgrade — recalcul du total).
     *
     * @param ModuleLineItem[] $lineItems
     */
    public function updateSubscription(Subscription $subscription, array $lineItems): SubscriptionSnapshot;

    /**
     * Annule complètement l'abonnement chez le provider (résiliation totale du compte,
     * pas juste un module — utilisé si le client désactive tous les modules ou clôture).
     */
    public function cancelSubscription(Subscription $subscription): void;

    /**
     * Ajoute un module à l'abonnement existant.
     * Retourne le nouveau snapshot (montant total mis à jour).
     */
    public function addModule(Subscription $subscription, array $currentLineItems, ModuleLineItem $newItem): SubscriptionSnapshot;

    /**
     * Retire un module de l'abonnement existant (recalcul du total au prochain cycle).
     */
    public function removeModule(Subscription $subscription, array $remainingLineItems): SubscriptionSnapshot;

    /**
     * Change le tier d'un module déjà actif (upgrade/downgrade en place).
     * Le provider gère la proration si possible (best-effort selon capacité du provider).
     */
    public function changeModuleTier(
        Subscription $subscription,
        array $currentLineItems,
        ModuleLineItem $updatedItem
    ): SubscriptionSnapshot;

    /**
     * Applique un coupon à l'abonnement — le montant transmis au provider
     * doit déjà refléter la réduction (voir CouponService).
     */
    public function applyCoupon(Subscription $subscription, Coupon $coupon, array $lineItems): SubscriptionSnapshot;

    /**
     * Traite un événement webhook brut reçu du provider.
     * Retourne les données normalisées nécessaires à la synchronisation locale.
     */
    public function handleWebhook(string $payload, array $headers): SubscriptionSnapshot|null;

    /**
     * Identifiant du provider (pour logs, colonne payment_provider, etc.)
     */
    public function providerName(): string;

    /**
     * Récupère l'état réel d'un abonnement chez le provider (source de vérité).
     * Utilisé pour confirmer une approbation après retour de popup/redirect.
     */
    public function retrieveSubscriptionStatus(string $providerSubscriptionId): SubscriptionSnapshot;
}
