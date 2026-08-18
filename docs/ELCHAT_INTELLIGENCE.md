# ELChat Intelligence — architecture et exploitation

## Gestion des ressources du site

Le dashboard expose un onglet **Ressources** pour les documents directement rattachés à un site.

- `GET /api/v1/site/{site}/documents` : liste paginée, recherche et métadonnées d’indexation.
- `POST /api/v1/site/{site}/documents` : upload d’un document avec un `title` métier.
- `POST /api/v1/site/{site}/documents/{document}` : modification du titre et remplacement optionnel du fichier.
- `DELETE /api/v1/site/{site}/documents/{document}` : suppression du fichier, des chunks SQL, des points Qdrant et des documents Meilisearch associés.
- `POST /api/v1/site/{site}/documents/{document}/reindex` : reconstruction complète des chunks et des deux index.

Le titre est injecté dans le document canonique avant le chunking. Il est donc présent dans le texte envoyé au service d’embedding, dans le texte lexical et dans les métadonnées des chunks. Chaque modification incrémente `index_revision`; un job plus ancien qui attend encore dans la queue est ignoré.

Un verrou `document-lifecycle:{uuid}` couvre l’indexation, la modification et la suppression. Le cache Laravel utilisé par les workers et l’API doit donc être partagé entre les processus (Redis recommandé en production). Une action concurrente du dashboard reçoit un HTTP `409` et peut être relancée quelques instants plus tard.

Les imports Produits et Pages restent visibles pour la traçabilité, mais se gèrent depuis leurs onglets métier car leur réimport exige le mapping de colonnes d’origine. Les sitemaps sont retraités par leur pipeline dédié.

Les nouveaux traitements sitemap renseignent `crawl_jobs.source_document_id`. Une suppression peut ainsi retirer les chunks directs du document et ceux des pages crawlées depuis ce sitemap avant que la cascade SQL ne supprime les tâches/pages associées. Les anciens sitemaps créés avant cette migration n’ont pas de liaison rétroactive fiable ; leurs pages historiques restent gérables depuis l’onglet Pages.

Les aperçus d’images et de PDF sont rendus localement par le navigateur. Aucun document privé n’est transmis à un service tiers de génération de miniatures.

### Déploiement de la gestion des ressources

1. Sauvegarder la base MySQL et les dossiers `public/assets/resources` et `public/assets/sitemaps`.
2. Déployer le backend et le dashboard dans la même release.
3. Exécuter `php artisan migrate --force`.
4. Redémarrer les workers de queue pour charger la nouvelle version de `IndexDocumentJob`.
5. Tester sur un site pilote : upload, modification du titre, remplacement, réindexation puis suppression.

La migration `2026_08_15_000004_add_management_fields_to_documents_table.php` n’ajoute aucun index ni aucune contrainte. La migration `2026_08_15_000005_link_sitemaps_to_crawl_jobs.php` utilise les noms explicites `cj_src_doc_idx` (14 caractères) et `cj_src_doc_fk` (13 caractères), très en dessous de la limite MySQL de 64 caractères.

## Finalité

ELChat Intelligence mesure la valeur métier réellement observée dans ELChat. Il ne remplace ni le RAG, ni QueryAnalyzer, ni les conversations, ni les agents, workflows ou connecteurs MCP. Les événements sont rattachés au compte et au site, enregistrés sans bloquer le parcours principal et ne doivent jamais être interprétés comme une conversion attribuée lorsque le lien n'est pas démontrable.

## Architecture

- `resource_events` reste la source brute unique. La migration `2026_08_15_000002` l'étend avec les dimensions d'attribution, de corrélation, d'agent, de workflow, de session et de valeur.
- `AnalyticsEventType` centralise les types extensibles. Ajouter un type ne nécessite pas de migration de schéma.
- `AnalyticsEventService` normalise, expurge les métadonnées sensibles et applique l'idempotence par site.
- `RecordAnalyticsEventJob` écrit sur la queue `analytics`. Une panne analytics est journalisée mais ne fait échouer ni conversation, ni agent, ni action MCP.
- `analytics_daily_metrics` contient les agrégats non personnels. `analytics_daily_aggregate_runs` certifie les journées complètement reconstruites.
- `AggregateAnalyticsDayJob` reconstruit une journée de façon idempotente. Le graphique historique utilise un agrégat uniquement pour une journée close et certifiée ; le jour courant reste lu en temps réel.
- `analytics:prune` supprime uniquement le brut non critique déjà agrégé. Leads, contacts, opportunités, rendez-vous et achats ne sont jamais supprimés par cette commande.
- `AnalyticsQueryService` produit les KPI, tendances, impacts, funnel séquentiel, connaissance, performances d'exécution, recommandations et anomalies.

## Attribution et fiabilité

- `direct` : résultat relié à une action ELChat vérifiée.
- `assisted` : ELChat a participé au parcours sans être revendiqué comme cause unique.
- `unknown` : lien non démontrable.

Le revenu est affiché uniquement quand un achat possède une valeur, une attribution directe ou assistée et une devise unique sur la période. Le funnel compte des corrélations ayant franchi les étapes dans l'ordre ; il ne rapproche pas des volumes indépendants.

Les recommandations utilisent des règles déterministes et un volume minimum configurable. Les anomalies comparent deux périodes équivalentes avec un seuil relatif configurable. Chaque résultat expose sa preuve, son interprétation prudente et l'action recommandée.

## Sécurité et confidentialité

- Toutes les API d'administration vérifient que le site appartient au compte de l'utilisateur authentifié.
- Les événements publics vérifient site, conversation, visiteur et message, sont limités en débit et exigent une origine widget autorisée.
- La clé d'idempotence envoyée par le navigateur n'est pas considérée comme fiable : le serveur la recalcule.
- Les métadonnées refusent notamment credentials, tokens, emails, téléphones, IP et contenu des messages.
- Knowledge masque emails, téléphones et liens avant affichage. Une confiance ou une source manquante non observée est renvoyée comme indisponible, jamais inventée.

## API

Sous `GET /api/site/{site}/analytics` avec authentification JWT :

- `/overview`
- `/business-impact`
- `/funnel`
- `/knowledge`
- `/agents`
- `/workflows`
- `/mcp`
- `/recommendations`
- `/anomalies`

Filtres communs : `from`, `to`, `channel`, `source`, `agent_id`, `workflow_id`, `event_type`. Le funnel accepte aussi `steps[]`, limité aux événements du registre.

## Dashboard Angular

L'onglet **Intelligence** du site fournit :

- une vue exécutive centrée leads, opportunités, rendez-vous et revenu attribuable ;
- la performance IA ;
- une tendance business ;
- un funnel séquentiel ;
- les anomalies et recommandations qui nécessitent une attention ;
- les lacunes de connaissance observées ;
- des périodes de 7, 30 et 90 jours, avec états de chargement, vide et erreur.

Aucune donnée fictive n'est présente dans le produit.

## Configuration

Variables facultatives :

```dotenv
ANALYTICS_ENABLED=true
ANALYTICS_ASYNC=true
ANALYTICS_QUEUE=analytics
ANALYTICS_RAW_RETENTION_DAYS=180
ANALYTICS_DAILY_AGGREGATION_ENABLED=true
ANALYTICS_DEFAULT_PERIOD_DAYS=30
ANALYTICS_MAX_PERIOD_DAYS=366
ANALYTICS_ANOMALY_RELATIVE_THRESHOLD=0.25
ANALYTICS_INSIGHT_MINIMUM_SAMPLE=10
ANALYTICS_EXECUTION_FAILURE_RATE_THRESHOLD=0.15
WIDGET_ORIGIN=https://adresse-du-widget.example
```

## Déploiement production

1. Sauvegarder MySQL et vérifier la capacité disque.
2. Positionner temporairement `ANALYTICS_ENABLED=false`, vider le cache de configuration et redémarrer les workers.
3. Déployer backend et frontend. Sur une table `resource_events` volumineuse, planifier la migration d'élargissement dans une fenêtre contrôlée ou avec l'outil de changement de schéma en ligne utilisé par l'exploitation.
4. Exécuter `php artisan migrate --force`.
5. Configurer Supervisor pour consommer aussi la queue analytics, par exemple `php artisan queue:work --queue=default,analytics --tries=5`, puis relire sa configuration et redémarrer les processus.
6. Vérifier que le cron Laravel appelle `php artisan schedule:run` chaque minute.
7. Construire les agrégats historiques par lots maîtrisés, par exemple `php artisan analytics:aggregate --date=2026-08-14 --days=31`, puis répéter pour les mois nécessaires. Surveiller la queue avant d'envoyer le lot suivant.
8. Positionner `ANALYTICS_ENABLED=true`, exécuter `php artisan config:cache`, puis redémarrer les workers pour qu'ils relisent la configuration.
9. Effectuer un parcours widget → conversation → CTA et vérifier le dashboard, les logs et la file `failed_jobs`.

## Retour arrière

Le retour arrière le plus sûr est applicatif : désactiver `ANALYTICS_ENABLED`, arrêter la consommation de la queue `analytics`, redéployer la version précédente et laisser les colonnes/tables additives en place. L'ancien code les ignore et les données restent récupérables.

Ne supprimer le schéma qu'après export et validation explicite : le rollback de `2026_08_15_000003` supprime les agrégats ; celui de `2026_08_15_000002` retire les dimensions d'attribution du brut. Les colonnes historiques restent élargies pour conserver les lignes, mais les dimensions retirées seraient perdues.

## Tests et contrôles

- Backend : création, expurgation, idempotence, isolation site, revenu attribué, funnel corrélé, recommandations, anomalies, agrégation et permission inter-tenant.
- Dashboard : appels filtrés, états vides/erreur, recommandations et anomalies.
- Widget : session stable, propagation au chat, identité visiteur et clé d'idempotence des ressources.
- Les builds dashboard et widget doivent être exécutés avant mise en production.

## Extension

Les agents, workflows et actions MCP partagent déjà les dimensions `agent_id`, `workflow_id`, `source`, `correlation_id` et `attribution_type`. Ils peuvent donc consommer ultérieurement les résultats d'Intelligence sans créer une deuxième banque d'agents ou un moteur parallèle. L'AI Sales Hunter installé depuis la marketplace utilise son identifiant d'agent dynamique et alimente les mêmes événements d'exécution et de résultat.
