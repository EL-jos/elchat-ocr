# Proactive Engagement

Cette fonctionnalité ajoute une couche d’engagement contextuel au-dessus des
événements, agents, workflows, canaux et de l’Event Intelligence déjà présents
dans ELChat. Elle reprend une conversation existante quand c’est possible ;
elle ne crée pas de conversation artificielle pour relancer un visiteur.

Pour l’équipe métier, le guide détaillé (quand utiliser la relance, choix de
chaque paramètre, valeurs recommandées et scénarios) est publié dans la
documentation utilisateur sous **Automatiser → Engagement proactif**.

## Architecture retenue

`resource_events` → `AnalyticsEventRecorded` → `ProactiveSequenceService` →
trigger/conditions → séquence → `SendProactiveMessageJob` →
`ProactivePolicyEngine` → agent/décision → `DeliveryChannel` → conversation /
canal omnicanal → événements/outcomes → attribution.

Le moteur ne connaît pas les détails de Facebook, Instagram, Telegram, email ou
du widget. Chaque canal implémente `DeliveryChannel`. WhatsApp reste fermé par
défaut tant qu’un driver sortant conforme aux règles du provider n’est pas
disponible.

## Données et migrations

- `2026_08_15_000006_create_proactive_engagement_tables.php` : campagnes,
  triggers, séquences, messages, deliveries, outcomes et journal d’audit.
- `2026_08_15_000007_add_proactive_permissions_to_mcp_agents.php` :
  `can_proactively_engage`, approbation obligatoire et périmètre de canaux de
  l’agent.
- `2026_08_15_000008_add_widget_tracking_to_proactive_messages.php` : états
  `notified_at`/`clicked_at` et index complémentaires pour les déploiements où
  la migration 000006 était déjà appliquée.
- `2026_08_15_000009_add_proactive_event_lookup_indexes.php` : index de lookup
  des signaux par visiteur dans `resource_events`.

Les clés d’idempotence sont uniques par tenant/site. Les index couvrent les
recherches par site, statut, date de planification, canal, conversation,
visiteur, agent et workflow.

## Services et jobs

- `ProactiveConditionEvaluator` : groupes `all`/`any`, opérateurs numériques,
  texte, présence, intervalle et booléens.
- `ProactiveScheduleService` : jours/heures/fuseau et prochain créneau valide.
- `ProactiveContextBuilder` : mémoire, résumé, derniers messages, événements et
  recherche RAG existante.
- `ProactiveDecisionService` : template approuvé ou décision IA contrainte par
  les faits observés.
- `ProactivePolicyEngine` : autorisation agent/workflow/canal, quotas,
  cooldown, horaires, limites tenant/site/visiteur/conversation, opt-out et
  réponse concurrente.
- `ProactiveDeliveryService` : verrou DB, retries/backoff, idempotence provider,
  audit et télémétrie.
- `ProactiveOutcomeService` : rattachement des réponses, leads, rendez-vous,
  opportunités et achats aux séquences, sans inventer de valeur.
- `SendProactiveMessageJob` : job unique et asynchrone sur la queue `proactive`.

L’endpoint `stats` expose les volumes envoyés/livrés/échoués, les retries,
ouvertures, réponses, taux et valeur attribuée disponibles dans les données.

Le scheduler Laravel récupère les messages dus chaque minute et répare les
verrous `processing` expirés. Les jobs sociaux restent ceux déjà fournis par
`SocialReplyDispatcher`.

## Événements

Les événements proactifs sont des valeurs de `AnalyticsEventType` et restent
dans `resource_events` : trigger reconnu, séquence démarrée, message planifié,
envoyé, livré, ouvert, répondu, ignoré/échoué, séquence arrêtée, conversion,
lead, rendez-vous, opportunité et vente attribuée. Les événements dont la
source est `proactive` sont explicitement exclus de l’évaluation des triggers
pour empêcher une boucle Agent → message → Agent.

## Capabilities existantes réutilisées

Le connecteur `elchat_platform` expose déjà :

- `proactive.schedule` (`schedule_proactive_message`),
- `proactive.stop` (`stop_proactive_sequence`),
- `proactive.read` (`get_proactive_status`, `get_proactive_history`).

Aucune capability concurrente n’a été créée.

## API d’administration

Sous `v1/site/{site}/proactive` (JWT, tenant isolé) :

- `GET/POST /campaigns`, `GET/PUT/DELETE /campaigns/{campaign}` ;
- `POST /campaigns/{campaign}/activate|pause|stop|schedule` ;
- `GET /messages`, `POST /messages/{message}/cancel`, `GET /messages/{message}/why` ;
- `GET /history`, `GET /outcomes`, `GET /stats`.

Le widget public utilise `v1/widget/proactive/pending/{site}` et les endpoints
`opened`/`opt-out`, protégés par l’identité visiteur + le site + la campagne.

## Frontend

- Dashboard Angular : `ProactiveEngagementComponent` (campagnes, messages,
  résultats, journal/Why?).
- Widget Angular : `ProactiveWidgetService`, reprise de la conversation,
  scroll vers le message, notification discrète ou ouverture automatique selon
  la policy, opt-out et réponse dans le fil historique.

## Variables d’environnement

```dotenv
PROACTIVE_ENGAGEMENT_ENABLED=true
PROACTIVE_QUEUE=proactive
PROACTIVE_SCAN_BATCH_SIZE=200
PROACTIVE_MAX_SITE_DAILY=2000
PROACTIVE_MAX_TENANT_DAILY=10000
PROACTIVE_MAX_VISITOR_DAILY=5
PROACTIVE_STALE_LOCK_MINUTES=15
PROACTIVE_OUTCOME_WINDOW_HOURS=168
PROACTIVE_DECISION_MODEL=
PROACTIVE_FACEBOOK_WINDOW_HOURS=24
PROACTIVE_INSTAGRAM_WINDOW_HOURS=24
```

Les workers Supervisor doivent consommer `proactive` en plus de `default` et
`analytics`, par exemple `php artisan queue:work database --queue=default,analytics,proactive --tries=1`.
Le scheduler Laravel doit être actif (`php artisan schedule:work` ou cron
`php artisan schedule:run`).

## Déploiement

1. Déployer le code en mode backward-compatible.
2. Exécuter `php artisan migrate --force`.
3. Vérifier `php artisan queue:failed` et démarrer/recharger les workers avec
   la queue `proactive`.
4. Laisser `PROACTIVE_ENGAGEMENT_ENABLED=false` tant que les canaux, agents,
   politiques et limites du tenant n’ont pas été vérifiés.
5. Créer un agent explicitement autorisé, créer une campagne brouillon,
   l’activer depuis le back-office, puis suivre les événements et outcomes.

## Scénario manuel de bout en bout

1. Créer un agent actif avec engagement proactif et canal `website` autorisés.
2. Créer une campagne active sur `commercial_intent_detected`, avec une
   condition de score et un délai de test court, puis l’activer.
3. Envoyer un message de devis depuis le widget et quitter la page.
4. Vérifier le trigger, la séquence et le message `scheduled`.
5. Lancer le scheduler/worker ; vérifier `sent`, `delivered` et le message dans
   la conversation existante.
6. Recharger le widget : `auto_open` ouvre la conversation et positionne le
   message ; `notification_only` affiche une notification sans détourner la
   conversation courante.
7. Répondre : la séquence passe à `replied`, les messages suivants sont
   annulés et l’outcome est attribué.
8. Rejouer avec un événement `meeting_booked` ou `purchase_completed` portant
   une valeur réelle pour vérifier l’attribution.

## Tests

- `tests/Unit/Proactive/ProactiveConditionEvaluatorTest.php` : groupes,
  opérateurs, valeurs absentes et évaluation fail-closed.
- `tests/Unit/Proactive/ProactiveScheduleServiceTest.php` : jours ouvrés et
  conversion de fuseau horaire.
- `tests/Feature/Proactive/ProactiveOutcomeTest.php` : réponse, arrêt,
  annulation des suivis, conversion et idempotence d’attribution.
- `tests/Feature/Proactive/ProactivePolicyTest.php` : désabonnement global
  respecté avant tout nouvel envoi.

Après installation des dépendances, exécuter `php artisan test` depuis
`backend/`. La suite Feature utilise SQLite en mémoire : PHP doit donc charger
`pdo_sqlite` et `sqlite3` (sous Windows, activer ces extensions dans `php.ini`).
Si les DLL sont présentes mais non activées dans la configuration globale, le
runner PHPUnit peut être lancé ponctuellement ainsi :

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --testsuite=Unit,Feature
```

Les contrôles statiques PHP peuvent être lancés sans `vendor/` avec `php -l`.

## Décisions restantes

- Activer WhatsApp sortant uniquement après validation de la fenêtre/template et
  du driver officiel du provider.
- Choisir la politique produit par défaut pour `notification_only` (texte,
  icône et durée) si le shell parent veut remplacer la notification intégrée.
- Brancher un signal de livraison provider réel pour les réseaux sociaux afin
  de distinguer définitivement `accepted` de `delivered`.
