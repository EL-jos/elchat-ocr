<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\MCPConnectorInterface;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Contracts\VerifiesConnectorConnection;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** WordPress REST API connector. It uses the connected site's credentials only. */
final class WordPressConnector implements MCPConnectorInterface, VerifiesConnectorConnection
{
    public function __construct(private readonly WordPressApiClient $api) {}

    public function slug(): string
    {
        return 'wordpress';
    }

    public function defaultTimeout(): int
    {
        return 12;
    }

    public function authenticate(array $credentials): array
    {
        try {
            return $this->api->normalizeCredentials($credentials);
        } catch (Throwable $exception) {
            throw new AuthExpiredException($exception->getMessage());
        }
    }

    public function verifyConnection(array $credentials): array
    {
        $credentials = $this->authenticate($credentials);
        try {
            $index = $this->api->apiIndex($credentials);
            $identity = $this->api->request($credentials, 'GET', 'wp/v2/users/me', ['context' => 'edit']);
        } catch (ConnectionException) {
            throw new ConnectorUnavailableException('Le site WordPress ne répond pas dans le délai autorisé.');
        } catch (Throwable $exception) {
            throw new ConnectorUnavailableException($exception->getMessage());
        }

        if ($identity->status() === 401) {
            throw new AuthExpiredException('Identifiants WordPress refusés. Vérifiez l’utilisateur et son mot de passe d’application.');
        }
        if ($identity->status() === 403) {
            throw new AuthExpiredException('Le compte WordPress ne peut pas accéder à l’API REST authentifiée.');
        }
        if (! $identity->successful() || ! is_array($identity->json())) {
            throw new ConnectorUnavailableException('La vérification de l’API REST WordPress a échoué.');
        }

        return [
            'connected' => true,
            'site' => [
                'name' => $index['name'] ?? null,
                'url' => $index['url'] ?? null,
                'namespaces' => array_values(array_filter($index['namespaces'] ?? [], 'is_string')),
            ],
            'user' => ['id' => $identity->json('id'), 'name' => $identity->json('name')],
        ];
    }

    public function listTools(): array
    {
        $tools = [];
        $add = function (string $name, string $description, array $properties = [], array $required = [], bool $write = false, string $mode = 'confirm') use (&$tools): void {
            $tools[] = new ToolSchema(
                'wordpress', $name, $description,
                ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                isWriteAction: $write,
                defaultActorScope: 'admin',
                defaultMode: $write ? $mode : 'auto',
                defaultConfirmActor: 'admin',
                capability: 'wordpress.'.$name,
            );
        };

        $add('test_connection', 'Vérifie que la connexion WordPress enregistrée répond et retourne l’identité du compte ainsi que les espaces REST disponibles. Utiliser pour diagnostiquer une connexion; ne modifie rien.', ['include_routes' => ['type' => 'boolean']], [], false);
        $add('get_site_info', 'Retourne les informations générales exposées par l’index REST WordPress. Utiliser avant une opération lorsque la cible ou le contexte du site ne sont pas connus. Les versions PHP/base de données ne sont présentes que si le pont ELChat est installé.', [], [], false);
        $add('discover_rest_api', 'Découvre les namespaces, routes et méthodes réellement exposés sur cette installation. Utiliser avant une fonction conditionnelle, un type de contenu personnalisé ou l’éditeur de site. La réponse est limitée aux routes publiques de découverte, sans secrets.', ['include_routes' => ['type' => 'boolean'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], [], false);
        $add('get_site_health', 'Exécute les contrôles de santé WordPress disponibles (mises à jour en arrière-plan, boucle locale, HTTPS, communication WordPress.org, en-tête Authorization et cache). Les tests peuvent lancer des requêtes réseau du site; chaque résultat indique clairement permission manquante ou test indisponible.', ['checks' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['background-updates', 'loopback-requests', 'https-status', 'dotorg-communication', 'authorization-header', 'page-cache']], 'maxItems' => 6]], [], false);
        $add('get_site_settings', 'Lit uniquement les réglages WordPress enregistrés et visibles par l’API REST pour ce compte. Les réglages privés ou non exposés ne sont pas accessibles.', [], [], false);
        $add('update_site_settings', 'Modifie un ou plusieurs réglages généraux explicitement pris en charge par WordPress. Fournis uniquement les champs à changer; l’URL du site et les options non enregistrées REST ne sont pas modifiables par cet outil. Vérifie le résultat après l’appel.', [
            'title' => ['type' => 'string', 'maxLength' => 200], 'description' => ['type' => 'string', 'maxLength' => 500],
            'timezone' => ['type' => 'string', 'maxLength' => 80], 'date_format' => ['type' => 'string', 'maxLength' => 80],
            'time_format' => ['type' => 'string', 'maxLength' => 80], 'start_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
            'language' => ['type' => 'string', 'maxLength' => 20], 'use_smilies' => ['type' => 'boolean'],
            'default_category' => ['type' => 'integer', 'minimum' => 1], 'default_post_format' => ['type' => 'string', 'maxLength' => 40],
            'posts_per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
            'show_on_front' => ['type' => 'string', 'enum' => ['posts', 'page']],
            'page_on_front' => ['type' => 'integer', 'minimum' => 0], 'page_for_posts' => ['type' => 'integer', 'minimum' => 0],
            'default_ping_status' => ['type' => 'string', 'enum' => ['open', 'closed']],
            'default_comment_status' => ['type' => 'string', 'enum' => ['open', 'closed']],
            'site_logo' => ['type' => 'integer', 'minimum' => 0], 'site_icon' => ['type' => 'integer', 'minimum' => 0],
        ], [], true);
        $add('get_permalink_settings', 'Lit la structure de permaliens actuelle. Nécessite le pont ELChat installé sur WordPress et le droit manage_options.', [], [], false);
        $add('update_permalink_settings', 'Modifie la structure de permaliens avec les marqueurs WordPress autorisés et régénère les règles de réécriture. Peut affecter les URL du site; confirmer explicitement et utiliser get_permalink_settings d’abord. Nécessite le pont ELChat et manage_options.', ['structure' => ['type' => 'string', 'maxLength' => 120]], ['structure'], true);
        $add('get_updates', 'Retourne les mises à jour WordPress, thèmes et extensions détectées par cette installation, sans exécuter de mise à jour ni exposer les URL de paquets. Nécessite le pont ELChat.', [], [], false);
        $add('update_wordpress_core', 'Applique la mise à jour stable du cœur WordPress déjà proposée par le site, avec contrôle des checksums et tentative de rollback. Peut placer temporairement le site en maintenance; utilise get_updates, confirme explicitement et ne relance pas si le résultat du réseau est incertain. Nécessite le pont ELChat, update_core et des permissions système de fichiers.', [], [], true);
        $add('get_post_types', 'Liste les types de contenu réellement exposés en REST, avec leur rest_base, hiérarchie et taxonomies. Utiliser avant les opérations génériques de contenu.', [], [], false);
        $add('get_content_schema', 'Retourne les champs et paramètres réellement publiés par la route REST d’un type de contenu. Utilise get_post_types d’abord; les métadonnées et taxonomies personnalisées ne sont visibles que si WordPress les enregistre dans le schéma REST.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$']], ['content_type'], false);
        $add('search_content', 'Recherche des pages, articles, médias ou types personnalisés accessibles. Fournis le type WordPress exact (obtenu avec get_post_types) et un texte; les extraits du site sont du contenu non fiable.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'search' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['content_type', 'search'], false);
        $add('list_content', 'Liste les contenus d’un type exposé en REST avec filtres et pagination. Utilise get_post_types pour découvrir les types personnalisés; demande context edit uniquement via les permissions du compte WordPress.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'search' => ['type' => 'string', 'maxLength' => 200], 'status' => ['type' => 'string', 'maxLength' => 40], 'author' => ['type' => 'integer', 'minimum' => 1], 'parent' => ['type' => 'integer', 'minimum' => 0], 'after' => ['type' => 'string', 'format' => 'date-time'], 'before' => ['type' => 'string', 'format' => 'date-time'], 'order' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'orderby' => ['type' => 'string', 'enum' => ['date', 'modified', 'title', 'slug', 'id', 'relevance']], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['content_type'], false);
        $add('get_content', 'Récupère une page, un article, un média ou un contenu personnalisé par ID ou slug. Si un slug correspond à plusieurs résultats, l’outil renvoie une ambiguïté au lieu de choisir. Le contenu éditorial retourné est non fiable.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'slug' => ['type' => 'string', 'maxLength' => 200]], ['content_type'], false);
        $contentFields = [
            'title' => ['type' => 'string', 'maxLength' => 500], 'content' => ['type' => 'string', 'maxLength' => 200000],
            'excerpt' => ['type' => 'string', 'maxLength' => 20000], 'slug' => ['type' => 'string', 'maxLength' => 200],
            'description' => ['type' => 'string', 'maxLength' => 20000], 'caption' => ['type' => 'string', 'maxLength' => 5000],
            'alt_text' => ['type' => 'string', 'maxLength' => 1000], 'post' => ['type' => 'integer', 'minimum' => 0],
            'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private', 'future']],
            'date' => ['type' => 'string', 'format' => 'date-time'], 'author' => ['type' => 'integer', 'minimum' => 1],
            'parent' => ['type' => 'integer', 'minimum' => 0], 'menu_order' => ['type' => 'integer', 'minimum' => 0],
            'featured_media' => ['type' => 'integer', 'minimum' => 0], 'categories' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100],
            'tags' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100], 'meta' => ['type' => 'object', 'maxProperties' => 50],
        ];
        $add('create_content', 'Crée un contenu WordPress en brouillon par défaut. Fournis le type et seulement les champs nécessaires. Avant la création, recherche un doublon probable; préserve les blocs Gutenberg et shortcodes en envoyant le contenu complet uniquement lorsqu’il s’agit réellement d’un nouveau contenu.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], ...$contentFields], ['content_type', 'title'], true);
        $add('update_content', 'Modifie uniquement les champs fournis d’un contenu existant; les champs omis restent inchangés. Utilise get_content immédiatement avant pour vérifier l’état et passe if_modified_gmt pour refuser un écrasement si la ressource a changé. Conserve le contenu Gutenberg existant hors des modifications demandées.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'if_modified_gmt' => ['type' => 'string', 'format' => 'date-time'], ...$contentFields], ['content_type', 'content_id'], true);
        $add('assign_content_terms', 'Remplace les termes associés au contenu pour la taxonomie indiquée par exactement la liste term_ids fournie; une liste vide retire toutes les associations. Vérifie le contenu et les termes avant confirmation. Le type et la taxonomie doivent être exposés et associés par WordPress.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'taxonomy' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'term_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'uniqueItems' => true, 'maxItems' => 100]], ['content_type', 'content_id', 'taxonomy', 'term_ids'], true);
        $add('delete_content', 'Déplace un contenu vers la corbeille par défaut. Pour une suppression définitive, fournir force=true seulement après confirmation explicite, vérification des dépendances et identification sans ambiguïté. Utilise get_content avant l’appel.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'force' => ['type' => 'boolean']], ['content_type', 'content_id'], true);
        $add('restore_content', 'Restaure un contenu de la corbeille avec le statut choisi. Vérifie son titre et ses dépendances avant restauration; publier nécessite un statut explicitement demandé et une confirmation.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']]], ['content_type', 'content_id', 'status'], true);
        $add('list_revisions', 'Liste les révisions accessibles d’un contenu. Le type doit exposer une route de révisions; une route absente signifie que le type ne prend pas en charge cette fonction REST.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['content_type', 'content_id'], false);
        $add('restore_revision', 'Restaure le titre, contenu, extrait et champs pris en charge d’une révision sur son contenu parent. Cette opération remplace ces champs; inspecte la révision et la ressource actuelle d’abord, puis confirme.', ['content_type' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'content_id' => ['type' => 'integer', 'minimum' => 1], 'revision_id' => ['type' => 'integer', 'minimum' => 1], 'if_modified_gmt' => ['type' => 'string', 'format' => 'date-time']], ['content_type', 'content_id', 'revision_id'], true);
        $add('list_taxonomies', 'Liste les taxonomies réellement exposées par l’API REST WordPress et indique les types de contenus associés.', [], [], false);
        $add('list_terms', 'Liste ou recherche les termes d’une taxonomie exposée en REST, avec pagination. Découvre d’abord les taxonomies avec list_taxonomies.', ['taxonomy' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'search' => ['type' => 'string', 'maxLength' => 200], 'parent' => ['type' => 'integer', 'minimum' => 0], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['taxonomy'], false);
        $add('get_term', 'Récupère un terme précis d’une taxonomie exposée en REST. Utilise list_terms pour identifier le terme sans ambiguïté.', ['taxonomy' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'term_id' => ['type' => 'integer', 'minimum' => 1]], ['taxonomy', 'term_id'], false);
        $add('create_term', 'Crée un terme dans une taxonomie REST connue. Vérifie auparavant qu’un terme équivalent n’existe pas; parent doit être un terme de la même taxonomie.', ['taxonomy' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'slug' => ['type' => 'string', 'maxLength' => 200], 'description' => ['type' => 'string', 'maxLength' => 5000], 'parent' => ['type' => 'integer', 'minimum' => 0]], ['taxonomy', 'name'], true);
        $add('update_term', 'Modifie seulement les champs fournis d’un terme; identifie-le avant la modification.', ['taxonomy' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'term_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 200], 'slug' => ['type' => 'string', 'maxLength' => 200], 'description' => ['type' => 'string', 'maxLength' => 5000], 'parent' => ['type' => 'integer', 'minimum' => 0]], ['taxonomy', 'term_id'], true);
        $add('delete_term', 'Supprime un terme et ses relations selon le comportement WordPress. Vérifie ses usages et demande confirmation avant l’appel.', ['taxonomy' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'term_id' => ['type' => 'integer', 'minimum' => 1]], ['taxonomy', 'term_id'], true);
        $add('list_comments', 'Recherche les commentaires par article, auteur ou état de modération. N’expose les données qu’au compte WordPress autorisé.', ['post' => ['type' => 'integer', 'minimum' => 1], 'author' => ['type' => 'integer', 'minimum' => 1], 'status' => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'trash']], 'search' => ['type' => 'string', 'maxLength' => 200], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], [], false);
        $add('get_comment', 'Récupère un commentaire identifié sans exposer son email ni son adresse IP. Utilise list_comments pour retrouver le commentaire avant modération ou suppression.', ['comment_id' => ['type' => 'integer', 'minimum' => 1]], ['comment_id'], false);
        $add('moderate_comment', 'Approuve, met en attente, marque comme indésirable ou restaure un commentaire. Vérifie le commentaire avant la modération; WordPress vérifie les capacités du compte.', ['comment_id' => ['type' => 'integer', 'minimum' => 1], 'status' => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'unspam']], 'content' => ['type' => 'string', 'maxLength' => 20000]], ['comment_id'], true);
        $add('delete_comment', 'Met un commentaire à la corbeille ou le supprime définitivement avec force=true. Vérifie l’auteur, le contenu et le statut avant une suppression définitive.', ['comment_id' => ['type' => 'integer', 'minimum' => 1], 'force' => ['type' => 'boolean']], ['comment_id'], true);
        $add('list_users', 'Recherche les utilisateurs WordPress accessibles et retourne uniquement les identifiants, noms publics et rôles; les emails et informations de profil privées sont omis de cette liste.', ['search' => ['type' => 'string', 'maxLength' => 200], 'role' => ['type' => 'string', 'maxLength' => 60], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], [], false);
        $add('list_roles', 'Liste les rôles WordPress que le compte connecté peut attribuer, avec les capacités déclarées. Nécessite le pont ELChat et promote_users; WordPress reste l’autorité pour toute attribution.', [], [], false);
        $add('get_user', 'Récupère le profil d’un utilisateur identifié. L’adresse email n’est retournée que par cette lecture ciblée et reste une donnée personnelle.', ['user_id' => ['type' => 'integer', 'minimum' => 1]], ['user_id'], false);
        $add('create_user', 'Crée un utilisateur avec une adresse email et un nom de compte. WordPress génère un secret aléatoire côté serveur; le pont ELChat envoie à l’adresse fournie un lien WordPress sécurisé pour choisir un mot de passe. Cet email est un effet de bord; confirme l’identité et le rôle avant l’appel. Le rôle est attribué uniquement si l’API WordPress l’autorise.', ['username' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60], 'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 254], 'name' => ['type' => 'string', 'maxLength' => 200], 'first_name' => ['type' => 'string', 'maxLength' => 100], 'last_name' => ['type' => 'string', 'maxLength' => 100], 'roles' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,60}$'], 'maxItems' => 5]], ['username', 'email'], true);
        $add('update_user', 'Modifie les champs de profil explicitement fournis. L’attribution d’un rôle peut élever des privilèges et nécessite une confirmation; WordPress applique les capacités assignable_roles.', ['user_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 200], 'first_name' => ['type' => 'string', 'maxLength' => 100], 'last_name' => ['type' => 'string', 'maxLength' => 100], 'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 254], 'url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 500], 'description' => ['type' => 'string', 'maxLength' => 5000], 'roles' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,60}$'], 'maxItems' => 5]], ['user_id'], true);
        $add('delete_user', 'Supprime un compte utilisateur en réattribuant obligatoirement son contenu à un autre utilisateur. Confirme le compte et le réassignataire; l’API WordPress applique ses règles multisites.', ['user_id' => ['type' => 'integer', 'minimum' => 1], 'reassign' => ['type' => 'integer', 'minimum' => 1]], ['user_id', 'reassign'], true);
        $add('list_plugins', 'Liste les extensions installées et leur état, version et prérequis. Retourne leur statut de mise à jour via le pont ELChat; si le statut est inconnu, ne pas conclure que l’extension est à jour. Utilise get_updates pour consulter toutes les mises à jour disponibles.', ['status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'network-active']]], [], false);
        $add('get_plugin', 'Récupère les informations d’une extension installée à partir de son identifiant exact retourné par list_plugins. Les avertissements de sécurité ne sont fournis que par les données exposées par WordPress.', ['plugin' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]+(?:/[a-z0-9_-]+)?\\.php$']], ['plugin'], false);
        $add('search_extensions', 'Recherche des extensions ou thèmes dans le répertoire officiel WordPress.org. Cette recherche ne touche pas au site. Utilise le résultat pour choisir un slug exact avant une installation.', ['kind' => ['type' => 'string', 'enum' => ['plugin', 'theme']], 'search' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], ['kind', 'search'], false);
        $add('install_plugin', 'Installe une extension depuis le répertoire WordPress.org via l’API native du site; aucun paquet arbitraire ou URL fournie par le modèle n’est accepté. Le slug doit provenir de search_extensions. Installation inactive par défaut.', ['slug' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,100}$']], ['slug'], true);
        $add('set_plugin_status', 'Active ou désactive une extension déjà installée, éventuellement à l’échelle réseau uniquement si l’API multisite le permet. Un changement peut interrompre des fonctions du site; identifie l’extension et son état avant confirmation.', ['plugin' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]+(?:/[a-z0-9_-]+)?(?:\.php)?$'], 'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'network-active']]], ['plugin', 'status'], true);
        $add('update_plugin', 'Met à jour une extension installée via le pont ELChat et le mécanisme officiel de mise à jour WordPress. Le paramètre plugin doit être le chemin exact du fichier PHP retourné par list_plugins, suffixe .php inclus (ex. dossier/extension.php). Vérifie les mises à jour et le rôle de l’extension avant confirmation; aucun code arbitraire n’est exécuté.', ['plugin' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]+(?:/[a-z0-9_-]+)?\.php$']], ['plugin'], true);
        $add('delete_plugin', 'Supprime une extension installée et inactive via l’API REST WordPress. Il faut d’abord la désactiver. La suppression est définitive et nécessite une confirmation.', ['plugin' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]+(?:/[a-z0-9_-]+)?\.php$']], ['plugin'], true);
        $add('list_themes', 'Liste les thèmes installés, leur état actif/inactif, version, thème parent, type bloc et prérequis. Les capacités sont celles du compte WordPress.', [], [], false);
        $add('get_theme', 'Récupère le détail d’un thème installé par son identifiant stylesheet. Utilise list_themes pour identifier le thème sans ambiguïté.', ['stylesheet' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$']], ['stylesheet'], false);
        $add('install_theme', 'Installe un thème du répertoire WordPress.org à partir de son slug exact via le pont ELChat; il reste inactif. Les paquets URL ou fichiers locaux ne sont jamais acceptés.', ['slug' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,100}$']], ['slug'], true);
        $add('activate_theme', 'Active un thème installé par son stylesheet. Vérifie sa compatibilité, son thème parent et l’impact visuel avant confirmation. L’API WordPress refuse selon les capacités multisites.', ['stylesheet' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$']], ['stylesheet'], true);
        $add('update_theme', 'Met à jour un thème depuis les métadonnées de mise à jour WordPress, via le pont ELChat. Vérifie le thème actif, les mises à jour disponibles et les dépendances avant confirmation.', ['stylesheet' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$']], ['stylesheet'], true);
        $add('delete_theme', 'Supprime un thème inactif qui n’est pas le parent du thème actif. Cette action est définitive; vérifie ces conditions et confirme avant l’appel.', ['stylesheet' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$']], ['stylesheet'], true);
        $add('list_site_editor_resources', 'Liste les ressources réellement exposées par l’éditeur de site : modèles, parties de modèles, styles globaux, widgets, sidebars et navigation. Ces routes dépendent de la version et du thème actif.', ['resource' => ['type' => 'string', 'enum' => ['templates', 'template-parts', 'global-styles', 'widgets', 'sidebars', 'navigation']], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['resource'], false);
        $add('get_site_editor_resource', 'Récupère une ressource de l’éditeur de site par son ID ou slug. Utilise list_site_editor_resources avant une modification; route indisponible signifie que le thème/version ne l’expose pas.', ['resource' => ['type' => 'string', 'enum' => ['templates', 'template-parts', 'global-styles', 'widgets', 'sidebars', 'navigation']], 'resource_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]], ['resource', 'resource_id'], false);
        $add('update_site_template', 'Modifie le titre ou le balisage de blocs d’un modèle ou d’une partie de modèle existant. Préserve les blocs et vérifie l’ID exact; les styles globaux et fichiers de thème ne sont pas modifiables par cet outil.', ['resource' => ['type' => 'string', 'enum' => ['templates', 'template-parts']], 'resource_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'title' => ['type' => 'string', 'maxLength' => 200], 'content' => ['type' => 'string', 'maxLength' => 100000]], ['resource', 'resource_id'], true);
        $add('list_menus', 'Liste les menus de navigation classiques, éléments, liens et emplacements du thème. Nécessite le pont ELChat et manage_options/edit_theme_options.', [], [], false);
        $add('create_menu', 'Crée un menu de navigation classique. Vérifie qu’un menu équivalent n’existe pas et demande confirmation.', ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80], 'location' => ['type' => 'string', 'maxLength' => 80]], ['name'], true);
        $add('update_menu', 'Renomme un menu ou assigne un emplacement de thème. L’emplacement doit être découvert avec list_menus. Nécessite le pont ELChat.', ['menu_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 80], 'location' => ['type' => 'string', 'maxLength' => 80]], ['menu_id'], true);
        $add('delete_menu', 'Supprime un menu et ses éléments et le retire des emplacements assignés. Cette action est définitive; vérifie le menu et ses usages, puis confirme.', ['menu_id' => ['type' => 'integer', 'minimum' => 1]], ['menu_id'], true);
        $add('add_menu_item', 'Ajoute un élément à un menu : lien interne vers un contenu publié ou URL personnalisée http/https relative. Les URLs javascript/data et destinations invalides sont refusées. Parent doit être un élément de ce menu.', ['menu_id' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'type' => ['type' => 'string', 'enum' => ['custom', 'post_type']], 'url' => ['type' => 'string', 'maxLength' => 1000], 'object' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$'], 'object_id' => ['type' => 'integer', 'minimum' => 1], 'parent_item_id' => ['type' => 'integer', 'minimum' => 0], 'position' => ['type' => 'integer', 'minimum' => 1]], ['menu_id', 'title', 'type'], true);
        $add('update_menu_item', 'Modifie, déplace ou renomme un élément existant du menu indiqué. Identifie d’abord le menu et l’élément; la destination suit les mêmes restrictions que add_menu_item.', ['menu_id' => ['type' => 'integer', 'minimum' => 1], 'item_id' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string', 'maxLength' => 200], 'url' => ['type' => 'string', 'maxLength' => 1000], 'parent_item_id' => ['type' => 'integer', 'minimum' => 0], 'position' => ['type' => 'integer', 'minimum' => 1]], ['menu_id', 'item_id'], true);
        $add('delete_menu_item', 'Supprime un élément du menu classique identifié. Vérifie le libellé et le menu avant confirmation.', ['menu_id' => ['type' => 'integer', 'minimum' => 1], 'item_id' => ['type' => 'integer', 'minimum' => 1]], ['menu_id', 'item_id'], true);
        $add('update_widget', 'Met à jour un widget texte actif via l’API REST native, en ne changeant que son titre, texte ou sidebar. Le widget doit être découvert avec list_site_editor_resources; WordPress vérifie les capacités et les champs de son type. Les widgets HTML personnalisés et le code arbitraire ne sont pas pris en charge.', ['widget_id' => ['type' => 'string', 'pattern' => '^text-\\d+$'], 'sidebar' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,100}$'], 'instance' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'maxLength' => 200], 'text' => ['type' => 'string', 'maxLength' => 10000]], 'additionalProperties' => false]], ['widget_id'], true);
        $add('upload_media', 'Téléverse une image JPEG/PNG/GIF/WebP ou un PDF de 4 Mo maximum au moyen de données base64. Le type réel est vérifié côté serveur; SVG, HTML, archives, URL distantes et fichiers exécutables sont refusés. Utilise uniquement pour un fichier fourni explicitement par l’utilisateur.', ['file_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 180], 'mime_type' => ['type' => 'string', 'enum' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']], 'base64_content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5592408], 'title' => ['type' => 'string', 'maxLength' => 200], 'caption' => ['type' => 'string', 'maxLength' => 5000], 'alt_text' => ['type' => 'string', 'maxLength' => 1000], 'post' => ['type' => 'integer', 'minimum' => 0]], ['file_name', 'mime_type', 'base64_content'], true);

        return $tools;
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        $tool = collect($this->listTools())->firstWhere('name', $toolName);
        if (! $tool instanceof ToolSchema) {
            throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour WordPress.");
        }
        $validationError = $this->validateToolParameters($params, $tool->parameters);
        if ($validationError !== null) {
            return ToolResult::fail('invalid_parameters', $validationError);
        }
        $credentials = $this->authenticate($credentials);

        return match ($toolName) {
            'test_connection' => $this->testConnectionTool($credentials, $params),
            'get_site_info' => $this->getSiteInfo($credentials),
            'discover_rest_api' => $this->discoverRestApi($credentials, $params),
            'get_site_health' => $this->getSiteHealth($credentials, $params),
            'get_site_settings' => $this->getSettings($credentials),
            'update_site_settings' => $this->updateSettings($credentials, $params),
            'get_permalink_settings' => $this->simpleBridgeRequest($credentials, 'GET', 'elchat/v1/permalinks', [], [], 'Permaliens récupérés.'),
            'update_permalink_settings' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/permalinks', [], ['structure' => $params['structure'] ?? ''], 'Structure de permaliens mise à jour.'),
            'get_updates' => $this->simpleBridgeRequest($credentials, 'GET', 'elchat/v1/updates', [], [], 'État des mises à jour récupéré.'),
            'update_wordpress_core' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/core/update', [], [], 'Mise à jour du cœur WordPress terminée.'),
            'get_post_types' => $this->getPostTypes($credentials),
            'get_content_schema' => $this->getContentSchema($credentials, $params),
            'search_content' => $this->listContent($credentials, $params, true),
            'list_content' => $this->listContent($credentials, $params),
            'get_content' => $this->getContent($credentials, $params),
            'create_content' => $this->writeContent($credentials, $params, 'POST'),
            'update_content' => $this->writeContent($credentials, $params, 'POST', true),
            'assign_content_terms' => $this->assignContentTerms($credentials, $params),
            'delete_content' => $this->deleteContent($credentials, $params),
            'restore_content' => $this->restoreContent($credentials, $params),
            'list_revisions' => $this->listRevisions($credentials, $params),
            'restore_revision' => $this->restoreRevision($credentials, $params),
            'list_taxonomies' => $this->listTaxonomies($credentials),
            'list_terms' => $this->listTerms($credentials, $params),
            'get_term' => $this->getTerm($credentials, $params),
            'create_term' => $this->writeTerm($credentials, $params, 'POST'),
            'update_term' => $this->writeTerm($credentials, $params, 'POST', true),
            'delete_term' => $this->deleteTerm($credentials, $params),
            'list_comments' => $this->listComments($credentials, $params),
            'get_comment' => $this->getComment($credentials, $params),
            'moderate_comment' => $this->moderateComment($credentials, $params),
            'delete_comment' => $this->deleteComment($credentials, $params),
            'list_users' => $this->listUsers($credentials, $params),
            'list_roles' => $this->simpleBridgeRequest($credentials, 'GET', 'elchat/v1/roles', [], [], 'Rôles et capacités accessibles récupérés.'),
            'get_user' => $this->getUser($credentials, $params),
            'create_user' => $this->createUser($credentials, $params),
            'update_user' => $this->updateUser($credentials, $params),
            'delete_user' => $this->deleteUser($credentials, $params),
            'list_plugins' => $this->listPlugins($credentials, $params),
            'get_plugin' => $this->getPlugin($credentials, $params),
            'search_extensions' => $this->searchExtensions($params),
            'install_plugin' => $this->installPlugin($credentials, $params),
            'set_plugin_status' => $this->setPluginStatus($credentials, $params),
            'update_plugin' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/plugins/'.$params['plugin'].'/update', [], [], 'Extension mise à jour.'),
            'delete_plugin' => $this->deletePlugin($credentials, $params),
            'list_themes' => $this->listThemes($credentials),
            'get_theme' => $this->getTheme($credentials, $params),
            'install_theme' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/themes/install', [], ['slug' => $params['slug'] ?? ''], 'Thème installé.'),
            'activate_theme' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/themes/'.$params['stylesheet'].'/activate', [], [], 'Thème activé.'),
            'update_theme' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/themes/'.$params['stylesheet'].'/update', [], [], 'Thème mis à jour.'),
            'delete_theme' => $this->simpleBridgeRequest($credentials, 'DELETE', 'elchat/v1/themes/'.$params['stylesheet'], [], [], 'Thème supprimé.'),
            'list_site_editor_resources' => $this->listSiteEditorResources($credentials, $params),
            'get_site_editor_resource' => $this->getSiteEditorResource($credentials, $params),
            'update_site_template' => $this->updateSiteTemplate($credentials, $params),
            'list_menus' => $this->simpleBridgeRequest($credentials, 'GET', 'elchat/v1/menus', [], [], 'Menus récupérés.'),
            'create_menu' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/menus', [], $params, 'Menu créé.'),
            'update_menu' => $this->updateMenu($credentials, $params),
            'add_menu_item' => $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/menus/'.(int) $params['menu_id'].'/items', [], $params, 'Élément de menu ajouté.'),
            'update_menu_item' => $this->updateMenuItem($credentials, $params),
            'delete_menu_item' => $this->simpleBridgeRequest($credentials, 'DELETE', 'elchat/v1/menus/'.(int) $params['menu_id'].'/items/'.(int) $params['item_id'], [], [], 'Élément de menu supprimé.'),
            'delete_menu' => $this->simpleBridgeRequest($credentials, 'DELETE', 'elchat/v1/menus/'.(int) $params['menu_id'], [], [], 'Menu supprimé.'),
            'update_widget' => $this->updateWidget($credentials, $params),
            'upload_media' => $this->uploadMedia($credentials, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour WordPress."),
        };
    }

    private function validateToolParameters(array $params, array $schema): ?string
    {
        if (($schema['type'] ?? null) !== 'object') {
            return 'Le schéma de paramètres WordPress est invalide.';
        }
        foreach (($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $params)) {
                return "Le paramètre requis '{$required}' est absent.";
            }
        }
        $properties = $schema['properties'] ?? [];
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($params) as $key) {
                if (! array_key_exists($key, $properties)) {
                    return "Le paramètre '{$key}' n’est pas pris en charge par cet outil.";
                }
            }
        }
        foreach ($params as $key => $value) {
            if (isset($properties[$key])) {
                $error = $this->validateSchemaValue($value, $properties[$key], (string) $key);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    private function validateSchemaValue(mixed $value, array $schema, string $path): ?string
    {
        $type = $schema['type'] ?? null;
        $validType = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => false,
        };
        if (! $validType) {
            return "Le paramètre '{$path}' doit respecter le type {$type}.";
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return "La valeur du paramètre '{$path}' n’est pas autorisée.";
        }
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                return "Le paramètre '{$path}' est trop court.";
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                return "Le paramètre '{$path}' est trop long.";
            }
            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\\/', $schema['pattern']).'/u', $value) !== 1) {
                return "Le paramètre '{$path}' ne respecte pas le format autorisé.";
            }
            if (($schema['format'] ?? null) === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                return "Le paramètre '{$path}' doit être une adresse email valide.";
            }
            if (($schema['format'] ?? null) === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                return "Le paramètre '{$path}' doit être une URL valide.";
            }
            if (($schema['format'] ?? null) === 'date-time' && strtotime($value) === false) {
                return "Le paramètre '{$path}' doit être une date/heure valide.";
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return "Le paramètre '{$path}' est inférieur à la limite autorisée.";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return "Le paramètre '{$path}' dépasse la limite autorisée.";
            }
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                    return "Le paramètre '{$path}' ne contient pas assez d’éléments.";
                }
                if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                    return "Le paramètre '{$path}' contient trop d’éléments.";
                }
                if (($schema['uniqueItems'] ?? false) && count(array_unique(array_map(static fn ($item) => serialize($item), $value))) !== count($value)) {
                    return "Le paramètre '{$path}' ne peut pas contenir de doublons.";
                }
                if (isset($schema['items']) && is_array($schema['items'])) {
                    foreach ($value as $index => $item) {
                        $error = $this->validateSchemaValue($item, $schema['items'], $path.'['.$index.']');
                        if ($error !== null) {
                            return $error;
                        }
                    }
                }
            } else {
                if (isset($schema['maxProperties']) && count($value) > $schema['maxProperties']) {
                    return "Le paramètre '{$path}' contient trop de propriétés.";
                }
                foreach ($value as $key => $item) {
                    if (isset($schema['properties'][$key])) {
                        $error = $this->validateSchemaValue($item, $schema['properties'][$key], $path.'.'.$key);
                        if ($error !== null) {
                            return $error;
                        }
                    } elseif (($schema['additionalProperties'] ?? true) === false) {
                        return "La propriété '{$path}.{$key}' n’est pas prise en charge.";
                    }
                }
            }
        }

        return null;
    }

    private function request(array $credentials, string $method, string $route, array $query = [], array $body = [], ?string $raw = null, ?string $mime = null, array $headers = []): Response|ToolResult
    {
        try {
            $response = $this->api->request($credentials, $method, $route, $query, $body, $raw, $mime, $headers);
        } catch (ConnectionException) {
            if ($method !== 'GET') {
                return ToolResult::fail('uncertain_result', 'Le délai réseau a expiré pendant une écriture. Le résultat est incertain; vérifie la ressource avant toute nouvelle tentative.');
            }
            throw new ConnectorUnavailableException('Le site WordPress ne répond pas dans le délai autorisé.');
        } catch (Throwable $exception) {
            if ($method !== 'GET') {
                return ToolResult::fail('uncertain_result', 'La requête d’écriture a échoué avant qu’un résultat fiable puisse être confirmé. Vérifie la ressource avant toute nouvelle tentative.');
            }
            throw new ConnectorUnavailableException($exception->getMessage());
        }

        if ($response->status() >= 200 && $response->status() < 300) {
            return $response;
        }
        $status = $response->status();
        if ($status === 401) {
            throw new AuthExpiredException('Les identifiants WordPress sont invalides ou révoqués.');
        }
        if ($status === 403) {
            if ($response->json('code') === 'elchat_missing_capability_update_plugins') {
                return ToolResult::fail('permission_denied', 'WordPress refuse la mise à jour : le compte doit disposer de la capacité update_plugins. Sur un multisite, cette opération est réservée au Super Admin du réseau.');
            }
            return ToolResult::fail('permission_denied', 'Le compte WordPress connecté ne dispose pas de la capacité nécessaire.');
        }
        if ($status === 404) {
            if ($response->json('code') === 'elchat_plugin_not_found') {
                return ToolResult::fail('plugin_not_found', 'Identifiant d’extension introuvable. Reprends exactement le chemin plugin renvoyé par list_plugins, avec le suffixe .php.');
            }
            return ToolResult::fail('not_found_or_unavailable', 'La ressource ou cette route REST est absente, ou n’est pas exposée par cette installation.');
        }
        if ($status === 409) {
            return ToolResult::fail('conflict', 'WordPress a refusé l’opération à cause d’un conflit. Relis l’état actuel avant de réessayer.');
        }
        if ($status === 429) {
            return ToolResult::fail('rate_limited', 'WordPress limite actuellement les requêtes. Réessaie plus tard.');
        }
        if (in_array($status, [400, 422], true)) {
            $code = (string) ($response->json('code') ?? 'invalid_request');
            if (preg_match('/^[a-z0-9_-]{1,80}$/i', $code) !== 1) {
                $code = 'invalid_request';
            }

            return ToolResult::fail($code, 'WordPress a rejeté les paramètres. Vérifie le schéma de la ressource et les champs pris en charge.');
        }
        if ($status >= 300 && $status < 400) {
            return ToolResult::fail('redirect_blocked', 'Le site a redirigé la requête REST; les redirections sont désactivées pour protéger les identifiants.');
        }
        Log::warning('MCP WordPress REST request failed', ['status' => $status, 'method' => $method]);

        return ToolResult::fail($method === 'GET' ? 'wordpress_unavailable' : 'uncertain_result', $method === 'GET'
            ? 'WordPress a renvoyé une erreur serveur.'
            : 'WordPress a renvoyé une erreur serveur pendant une écriture. Le résultat peut être incertain; vérifie la ressource avant toute nouvelle tentative.');
    }

    private function data(Response|ToolResult $response): array|ToolResult
    {
        if ($response instanceof ToolResult) {
            return $response;
        }
        $data = $response->json();
        if (! is_array($data)) {
            return ToolResult::fail('invalid_response', 'La réponse REST WordPress n’est pas un JSON exploitable.');
        }

        return $this->stripSensitiveFields($data);
    }

    private function stripSensitiveFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/(?:password|secret|token|api[_-]?key|access[_-]?key|consumer[_-]?key|authorization|cookie|credential|nonce|package|download[_-]?url)/i', (string) $key) === 1) {
                unset($data[$key]);

                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->stripSensitiveFields($value);
            }
        }

        return $data;
    }

    private function safeUpdateInfo(mixed $update): array|bool|null
    {
        if (is_object($update)) {
            $update = (array) $update;
        }
        if (is_bool($update)) {
            return $update;
        }
        if (! is_array($update)) {
            return null;
        }
        $details = array_intersect_key($update, array_flip(['new_version', 'version', 'requires', 'requires_php', 'tested', 'autoupdate']));

        return ['available' => true, ...$details];
    }

    private function ok(array $data, string $summary): ToolResult
    {
        return ToolResult::ok($data, $summary);
    }

    private function simpleBridgeRequest(array $credentials, string $method, string $route, array $query, array $body, string $summary): ToolResult
    {
        $data = $this->data($this->request($credentials, $method, $route, $query, $body));

        return $data instanceof ToolResult ? $data : $this->ok(['result' => $data], $summary);
    }

    private function testConnectionTool(array $credentials, array $params): ToolResult
    {
        try {
            $info = $this->verifyConnection($credentials);
            if (! empty($params['include_routes'])) {
                $index = $this->api->apiIndex($credentials);
                $info['routes'] = $this->safeRoutes($index['routes'] ?? [], 100);
            }

            return $this->ok($info, 'Connexion WordPress vérifiée.');
        } catch (AuthExpiredException $exception) {
            return ToolResult::fail('auth_expired', $exception->getMessage());
        } catch (Throwable $exception) {
            return ToolResult::fail('connection_failed', $exception->getMessage());
        }
    }

    private function getSiteInfo(array $credentials): ToolResult
    {
        $index = $this->data($this->request($credentials, 'GET', ''));
        if ($index instanceof ToolResult) {
            return $index;
        }
        $diagnostics = $this->data($this->request($credentials, 'GET', 'elchat/v1/diagnostics'));
        $diagnostics = $diagnostics instanceof ToolResult ? null : $diagnostics;

        return $this->ok([
            'name' => $index['name'] ?? null, 'url' => $index['url'] ?? null, 'description' => $index['description'] ?? null,
            'home' => $index['home'] ?? null, 'gmt_offset' => $index['gmt_offset'] ?? null,
            'timezone_string' => $index['timezone_string'] ?? null, 'namespaces' => $index['namespaces'] ?? [],
            'site_health_route_available' => isset($index['routes']['wp-site-health/v1/tests']),
            'diagnostics' => $diagnostics,
            'diagnostic_limits' => $diagnostics === null ? ['php_version' => 'bridge_required', 'database_version' => 'bridge_required', 'updates' => 'bridge_required'] : [],
        ], 'Informations du site WordPress récupérées.');
    }

    private function discoverRestApi(array $credentials, array $params): ToolResult
    {
        $index = $this->data($this->request($credentials, 'GET', ''));
        if ($index instanceof ToolResult) {
            return $index;
        }
        $routes = ! empty($params['include_routes']) ? $this->safeRoutes($index['routes'] ?? [], (int) ($params['limit'] ?? 100)) : [];

        return $this->ok(['namespaces' => array_values(array_filter($index['namespaces'] ?? [], 'is_string')), 'route_count' => count($index['routes'] ?? []), 'routes' => $routes], 'API REST WordPress découverte.');
    }

    private function safeRoutes(array $routes, int $limit): array
    {
        $safe = [];
        foreach ($routes as $route => $details) {
            if (! is_string($route) || ! is_array($details) || ! str_starts_with($route, '/wp/v2/') && ! str_starts_with($route, '/elchat/v1/') && ! str_starts_with($route, '/wp-site-health/')) {
                continue;
            }
            $methods = [];
            foreach (($details['endpoints'] ?? []) as $endpoint) {
                foreach (($endpoint['methods'] ?? []) as $method) {
                    if (is_string($method)) {
                        $methods[] = $method;
                    }
                }
            }
            $safe[] = ['route' => $route, 'methods' => array_values(array_unique($methods))];
            if (count($safe) >= max(1, min(200, $limit))) {
                break;
            }
        }

        return $safe;
    }

    private function getSiteHealth(array $credentials, array $params): ToolResult
    {
        $allowed = ['background-updates', 'loopback-requests', 'https-status', 'dotorg-communication', 'authorization-header', 'page-cache'];
        $checks = array_values(array_unique($params['checks'] ?? $allowed));
        $results = [];
        foreach ($checks as $check) {
            if (! in_array($check, $allowed, true)) {
                continue;
            }
            $data = $this->data($this->request($credentials, 'GET', 'wp-site-health/v1/tests/'.$check));
            if ($data instanceof ToolResult) {
                $results[] = ['check' => $check, 'status' => 'unavailable', 'reason' => $data->errorCode === 'permission_denied' ? 'permission_denied' : 'not_exposed'];

                continue;
            }
            $results[] = ['check' => $check, 'label' => $data['label'] ?? null, 'status' => $data['status'] ?? 'unknown', 'description' => isset($data['description']) ? trim(strip_tags((string) $data['description'])) : null, 'badge' => $data['badge']['label'] ?? null];
        }

        return $this->ok(['checks' => $results], count($results).' contrôle(s) de santé exécuté(s).');
    }

    private function getSettings(array $credentials): ToolResult
    {
        $settings = $this->data($this->request($credentials, 'GET', 'wp/v2/settings'));

        return $settings instanceof ToolResult ? $settings : $this->ok(['settings' => $settings], 'Réglages WordPress récupérés.');
    }

    private function updateSettings(array $credentials, array $params): ToolResult
    {
        unset($params['content_type']);
        if ($params === []) {
            return ToolResult::fail('missing_fields', 'Fournis au moins un réglage à modifier.');
        }
        $updated = $this->data($this->request($credentials, 'POST', 'wp/v2/settings', [], $params));
        if ($updated instanceof ToolResult) {
            return $updated;
        }
        $verified = $this->data($this->request($credentials, 'GET', 'wp/v2/settings'));
        if ($verified instanceof ToolResult) {
            return $this->ok([
                'settings' => $updated,
                'changed_fields' => array_keys($params),
                'verification' => ['status' => 'unavailable', 'reason' => $verified->errorCode],
            ], 'WordPress a accepté les réglages, mais la lecture de vérification n’a pas abouti. Ne relance pas sans vérifier le site.');
        }
        $mismatched = [];
        foreach ($params as $key => $value) {
            if (! array_key_exists($key, $verified) || $verified[$key] != $value) {
                $mismatched[] = $key;
            }
        }

        return $this->ok([
            'settings' => $verified,
            'changed_fields' => array_keys($params),
            'verification' => ['status' => $mismatched === [] ? 'confirmed' : 'mismatch', 'mismatched_fields' => $mismatched],
        ], $mismatched === [] ? 'Réglages WordPress modifiés et vérifiés.' : 'Réglages acceptés par WordPress, mais certains champs diffèrent de la valeur demandée.');
    }

    private function getPostTypes(array $credentials): ToolResult
    {
        $types = $this->data($this->request($credentials, 'GET', 'wp/v2/types', ['context' => 'view']));
        if ($types instanceof ToolResult) {
            return $types;
        }
        $safe = [];
        foreach ($types as $slug => $type) {
            if (! is_string($slug) || ! is_array($type)) {
                continue;
            }
            $safe[] = ['slug' => $slug, 'name' => $type['name'] ?? null, 'rest_base' => $type['rest_base'] ?? $slug, 'hierarchical' => $type['hierarchical'] ?? false, 'taxonomies' => $type['taxonomies'] ?? [], 'supports' => $type['supports'] ?? []];
        }

        return $this->ok(['post_types' => $safe], count($safe).' types de contenu trouvés.');
    }

    private function getContentSchema(array $credentials, array $params): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $index = $this->data($this->request($credentials, 'GET', ''));
        if ($index instanceof ToolResult) {
            return $index;
        }
        $route = '/wp/v2/'.$base;
        $endpoints = $index['routes'][$route]['endpoints'] ?? null;
        if (! is_array($endpoints)) {
            return ToolResult::fail('schema_unavailable', 'WordPress n’a pas publié de schéma pour cette route REST.');
        }
        $methods = [];
        $fields = [];
        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }
            foreach (($endpoint['methods'] ?? []) as $method) {
                if (is_string($method)) {
                    $methods[] = $method;
                }
            }
            foreach (($endpoint['args'] ?? []) as $name => $argument) {
                if (! is_string($name) || ! is_array($argument) || count($fields) >= 150) {
                    continue;
                }
                $fields[$name] = $this->safeSchemaDefinition($argument);
            }
        }

        return $this->ok([
            'route' => $route,
            'methods' => array_values(array_unique($methods)),
            'fields' => $fields,
        ], count($fields).' champ(s) publiés par le schéma REST.');
    }

    private function safeSchemaDefinition(array $definition, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }
        $allowed = ['type', 'required', 'description', 'enum', 'format', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'readonly', 'context', 'items', 'properties'];
        $safe = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $definition)) {
                continue;
            }
            $value = $definition[$key];
            if ($key === 'items' && is_array($value)) {
                $safe[$key] = $this->safeSchemaDefinition($value, $depth + 1);
            } elseif ($key === 'properties' && is_array($value)) {
                $safe[$key] = [];
                foreach (array_slice($value, 0, 100, true) as $name => $property) {
                    if (is_string($name) && is_array($property)) {
                        $safe[$key][$name] = $this->safeSchemaDefinition($property, $depth + 1);
                    }
                }
            } elseif (is_scalar($value) || (is_array($value) && array_is_list($value))) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function resolvePostType(array $credentials, string $postType): string|ToolResult
    {
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $postType) !== 1) {
            return ToolResult::fail('invalid_content_type', 'Le type de contenu doit être un identifiant WordPress valide.');
        }
        $types = $this->data($this->request($credentials, 'GET', 'wp/v2/types', ['context' => 'view']));
        if ($types instanceof ToolResult) {
            return $types;
        }
        $type = $types[$postType] ?? null;
        $base = is_array($type) ? ($type['rest_base'] ?? $postType) : null;
        if (! is_string($base) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $base) !== 1) {
            return ToolResult::fail('unsupported_content_type', 'Ce type n’est pas exposé avec une route REST sécurisée. Utilise get_post_types.');
        }

        return $base;
    }

    private function pagination(array $params): array
    {
        return ['page' => max(1, min(100, (int) ($params['page'] ?? 1))), 'per_page' => max(1, min(100, (int) ($params['per_page'] ?? 20)))];
    }

    private function listContent(array $credentials, array $params, bool $searchOnly = false): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $query = ['context' => 'edit', ...$this->pagination($params)];
        foreach (['search', 'status', 'author', 'parent', 'after', 'before', 'order', 'orderby'] as $key) {
            if (array_key_exists($key, $params)) {
                $query[$key] = $params[$key];
            }
        }
        if ($searchOnly && empty($params['search'])) {
            return ToolResult::fail('invalid_request', 'Un texte de recherche est requis.');
        }
        $response = $this->request($credentials, 'GET', 'wp/v2/'.$base, $query);
        $items = $this->data($response);
        if ($items instanceof ToolResult) {
            return $items;
        }
        $mapped = array_map(fn ($item) => $this->contentSummary($item), $items);
        if ($searchOnly && $mapped === []) {
            return ToolResult::fail('not_found', 'Aucun contenu correspondant.');
        }

        return $this->ok(['items' => $mapped, 'pagination' => ['page' => $query['page'], 'per_page' => $query['per_page'], 'total' => (int) $response->header('X-WP-Total'), 'total_pages' => (int) $response->header('X-WP-TotalPages')]], count($mapped).' contenu(s) récupéré(s).');
    }

    private function getContent(array $credentials, array $params): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        if (! empty($params['content_id'])) {
            $content = $this->data($this->request($credentials, 'GET', 'wp/v2/'.$base.'/'.(int) $params['content_id'], ['context' => 'edit']));

            return $content instanceof ToolResult ? $content : $this->ok(['content' => $content, 'content_is_untrusted' => true], 'Contenu récupéré.');
        }
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            return ToolResult::fail('missing_identifier', 'Fournis content_id ou slug.');
        }
        $items = $this->data($this->request($credentials, 'GET', 'wp/v2/'.$base, ['slug' => $slug, 'context' => 'edit', 'per_page' => 3]));
        if ($items instanceof ToolResult) {
            return $items;
        }
        if (count($items) === 0) {
            return ToolResult::fail('not_found', 'Aucun contenu avec ce slug.');
        }
        if (count($items) !== 1) {
            return ToolResult::fail('ambiguous', 'Plusieurs résultats correspondent au slug; précise un identifiant stable.');
        }

        return $this->ok(['content' => $items[0], 'content_is_untrusted' => true], 'Contenu récupéré par slug.');
    }

    private function contentPayload(array $params): array
    {
        $allowed = ['title', 'content', 'excerpt', 'description', 'caption', 'alt_text', 'post', 'slug', 'status', 'date', 'author', 'parent', 'menu_order', 'featured_media', 'categories', 'tags', 'meta'];

        return array_intersect_key($params, array_flip($allowed));
    }

    private function writeContent(array $credentials, array $params, string $method, bool $update = false): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $payload = $this->contentPayload($params);
        if (! $update && ! array_key_exists('status', $payload)) {
            $payload['status'] = 'draft';
        }
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Aucun champ éditorial à enregistrer.');
        }
        $route = 'wp/v2/'.$base;
        if ($update) {
            $id = (int) ($params['content_id'] ?? 0);
            if ($id < 1) {
                return ToolResult::fail('invalid_id', 'content_id doit être un entier positif.');
            }
            if (! empty($params['if_modified_gmt'])) {
                $current = $this->data($this->request($credentials, 'GET', $route.'/'.$id, ['context' => 'edit']));
                if ($current instanceof ToolResult) {
                    return $current;
                }
                if (($current['modified_gmt'] ?? null) !== $params['if_modified_gmt']) {
                    return ToolResult::fail('conflict', 'Le contenu a été modifié depuis sa dernière lecture. Relis-le et réapplique uniquement les changements demandés.');
                }
            }
            $route .= '/'.$id;
        }
        $result = $this->data($this->request($credentials, $method, $route, [], $payload));

        return $result instanceof ToolResult ? $result : $this->ok(['content' => $result, 'content_is_untrusted' => true, 'changed_fields' => array_keys($payload)], $update ? 'Contenu modifié.' : 'Contenu créé.');
    }

    private function deleteContent(array $credentials, array $params): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $force = (bool) ($params['force'] ?? false);
        $deleted = $this->data($this->request($credentials, 'DELETE', 'wp/v2/'.$base.'/'.(int) $params['content_id'], ['force' => $force]));

        return $deleted instanceof ToolResult ? $deleted : $this->ok(['content' => $deleted, 'deleted_permanently' => $force], $force ? 'Contenu supprimé définitivement.' : 'Contenu déplacé vers la corbeille.');
    }

    private function restoreContent(array $credentials, array $params): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $content = $this->data($this->request($credentials, 'POST', 'wp/v2/'.$base.'/'.(int) $params['content_id'], [], ['status' => (string) $params['status']]));

        return $content instanceof ToolResult ? $content : $this->ok(['content' => $content, 'restored' => true, 'content_is_untrusted' => true], 'Contenu restauré avec le statut demandé.');
    }

    private function contentSummary(array $item): array
    {
        return ['id' => $item['id'] ?? null, 'type' => $item['type'] ?? null, 'title' => $item['title']['rendered'] ?? $item['title']['raw'] ?? $item['title'] ?? null, 'slug' => $item['slug'] ?? null, 'status' => $item['status'] ?? null, 'modified_gmt' => $item['modified_gmt'] ?? null, 'link' => $item['link'] ?? null, 'author' => $item['author'] ?? null, 'parent' => $item['parent'] ?? null];
    }

    private function listRevisions(array $credentials, array $params): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $response = $this->request($credentials, 'GET', 'wp/v2/'.$base.'/'.(int) $params['content_id'].'/revisions', ['context' => 'edit', ...$this->pagination($params)]);
        $items = $this->data($response);
        if ($items instanceof ToolResult) {
            return $items;
        }

        return $this->ok(['revisions' => array_map(fn ($item) => ['id' => $item['id'] ?? null, 'parent' => $item['parent'] ?? null, 'author' => $item['author'] ?? null, 'date' => $item['date'] ?? null, 'modified_gmt' => $item['modified_gmt'] ?? null, 'title' => $item['title']['raw'] ?? $item['title']['rendered'] ?? null], $items)], count($items).' révision(s) trouvée(s).');
    }

    private function restoreRevision(array $credentials, array $params): ToolResult
    {
        $base = $this->resolvePostType($credentials, (string) ($params['content_type'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $id = (int) $params['content_id'];
        $revision = $this->data($this->request($credentials, 'GET', 'wp/v2/'.$base.'/'.$id.'/revisions/'.(int) $params['revision_id'], ['context' => 'edit']));
        if ($revision instanceof ToolResult) {
            return $revision;
        }
        if ((int) ($revision['parent'] ?? 0) !== $id) {
            return ToolResult::fail('invalid_revision', 'Cette révision n’appartient pas au contenu demandé.');
        }
        if (! empty($params['if_modified_gmt'])) {
            $current = $this->data($this->request($credentials, 'GET', 'wp/v2/'.$base.'/'.$id, ['context' => 'edit']));
            if ($current instanceof ToolResult) {
                return $current;
            }
            if (($current['modified_gmt'] ?? null) !== $params['if_modified_gmt']) {
                return ToolResult::fail('conflict', 'Le contenu a changé depuis sa dernière lecture. Relis-le avant restauration.');
            }
        }
        $payload = [];
        foreach (['title', 'content', 'excerpt'] as $field) {
            if (isset($revision[$field]['raw'])) {
                $payload[$field] = $revision[$field]['raw'];
            }
        }
        if ($payload === []) {
            return ToolResult::fail('unsupported_revision', 'La révision ne contient aucun champ REST modifiable.');
        }
        $restored = $this->data($this->request($credentials, 'POST', 'wp/v2/'.$base.'/'.$id, [], $payload));

        return $restored instanceof ToolResult ? $restored : $this->ok(['content' => $restored, 'restored_revision_id' => (int) $revision['id'], 'content_is_untrusted' => true], 'Révision restaurée sur le contenu parent.');
    }

    private function resolveTaxonomy(array $credentials, string $taxonomy): string|ToolResult
    {
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $taxonomy) !== 1) {
            return ToolResult::fail('invalid_taxonomy', 'Identifiant de taxonomie invalide.');
        }
        $taxonomies = $this->data($this->request($credentials, 'GET', 'wp/v2/taxonomies', ['context' => 'view']));
        if ($taxonomies instanceof ToolResult) {
            return $taxonomies;
        }
        $base = $taxonomies[$taxonomy]['rest_base'] ?? null;
        if (! is_string($base) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $base) !== 1) {
            return ToolResult::fail('unsupported_taxonomy', 'Cette taxonomie n’est pas exposée par l’API REST.');
        }

        return $base;
    }

    private function listTaxonomies(array $credentials): ToolResult
    {
        $taxonomies = $this->data($this->request($credentials, 'GET', 'wp/v2/taxonomies', ['context' => 'view']));
        if ($taxonomies instanceof ToolResult) {
            return $taxonomies;
        }
        $safe = [];
        foreach ($taxonomies as $slug => $taxonomy) {
            if (! is_string($slug) || ! is_array($taxonomy)) {
                continue;
            }
            $safe[] = ['slug' => $slug, 'name' => $taxonomy['name'] ?? null, 'rest_base' => $taxonomy['rest_base'] ?? null, 'hierarchical' => $taxonomy['hierarchical'] ?? false, 'types' => $taxonomy['types'] ?? [], 'visibility' => $taxonomy['visibility'] ?? null];
        }

        return $this->ok(['taxonomies' => $safe], count($safe).' taxonomie(s) disponibles.');
    }

    private function listTerms(array $credentials, array $params): ToolResult
    {
        $base = $this->resolveTaxonomy($credentials, (string) ($params['taxonomy'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $query = ['context' => 'view', ...$this->pagination($params)];
        foreach (['search', 'parent'] as $key) {
            if (array_key_exists($key, $params)) {
                $query[$key] = $params[$key];
            }
        }
        $response = $this->request($credentials, 'GET', 'wp/v2/'.$base, $query);
        $terms = $this->data($response);

        return $terms instanceof ToolResult ? $terms : $this->ok(['terms' => $terms, 'pagination' => ['total' => (int) $response->header('X-WP-Total'), 'total_pages' => (int) $response->header('X-WP-TotalPages')]], count($terms).' terme(s) récupéré(s).');
    }

    private function getTerm(array $credentials, array $params): ToolResult
    {
        $base = $this->resolveTaxonomy($credentials, (string) ($params['taxonomy'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $term = $this->data($this->request($credentials, 'GET', 'wp/v2/'.$base.'/'.(int) ($params['term_id'] ?? 0), ['context' => 'view']));

        return $term instanceof ToolResult ? $term : $this->ok(['term' => $term], 'Terme récupéré.');
    }

    private function assignContentTerms(array $credentials, array $params): ToolResult
    {
        $type = (string) ($params['content_type'] ?? '');
        $taxonomy = (string) ($params['taxonomy'] ?? '');
        $types = $this->data($this->request($credentials, 'GET', 'wp/v2/types', ['context' => 'view']));
        if ($types instanceof ToolResult) {
            return $types;
        }
        if (! isset($types[$type]) || ! is_array($types[$type])) {
            return ToolResult::fail('unsupported_post_type', 'Ce type de contenu n’est pas exposé en REST.');
        }
        if (! in_array($taxonomy, $types[$type]['taxonomies'] ?? [], true)) {
            return ToolResult::fail('unsupported_taxonomy', 'Cette taxonomie n’est pas exposée ou associée à ce type de contenu.');
        }
        $taxonomies = $this->data($this->request($credentials, 'GET', 'wp/v2/taxonomies', ['context' => 'view']));
        if ($taxonomies instanceof ToolResult) {
            return $taxonomies;
        }
        $restBase = $taxonomies[$taxonomy]['rest_base'] ?? $taxonomy;
        $postBase = $types[$type]['rest_base'] ?? null;
        if (! is_string($postBase) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $postBase) !== 1
            || ! is_string($restBase) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $restBase) !== 1) {
            return ToolResult::fail('unsupported_taxonomy', 'Les routes REST de cette taxonomie ne sont pas valides.');
        }
        $ids = array_values(array_unique(array_map('intval', $params['term_ids'] ?? [])));
        $updated = $this->data($this->request($credentials, 'POST', 'wp/v2/'.$postBase.'/'.(int) ($params['content_id'] ?? 0), [], [$restBase => $ids]));

        return $updated instanceof ToolResult ? $updated : $this->ok(['content' => $updated, 'taxonomy' => $taxonomy, 'term_ids' => $ids], 'Termes du contenu remplacés.');
    }

    private function writeTerm(array $credentials, array $params, string $method, bool $update = false): ToolResult
    {
        $base = $this->resolveTaxonomy($credentials, (string) ($params['taxonomy'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $fields = ['name', 'slug', 'description', 'parent'];
        $payload = array_intersect_key($params, array_flip($fields));
        if ($update && $payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis au moins un champ de terme à modifier.');
        }
        if ($update) {
            $base .= '/'.(int) ($params['term_id'] ?? 0);
        }
        $term = $this->data($this->request($credentials, $method, 'wp/v2/'.$base, [], $payload));

        return $term instanceof ToolResult ? $term : $this->ok(['term' => $term], $update ? 'Terme modifié.' : 'Terme créé.');
    }

    private function deleteTerm(array $credentials, array $params): ToolResult
    {
        $base = $this->resolveTaxonomy($credentials, (string) ($params['taxonomy'] ?? ''));
        if ($base instanceof ToolResult) {
            return $base;
        }
        $term = $this->data($this->request($credentials, 'DELETE', 'wp/v2/'.$base.'/'.(int) $params['term_id'], ['force' => true]));

        return $term instanceof ToolResult ? $term : $this->ok(['deleted_term' => $term], 'Terme supprimé.');
    }

    private function listComments(array $credentials, array $params): ToolResult
    {
        $query = ['context' => 'edit', ...$this->pagination($params)];
        foreach (['post', 'author', 'status', 'search'] as $key) {
            if (array_key_exists($key, $params)) {
                $query[$key] = $params[$key];
            }
        }
        $response = $this->request($credentials, 'GET', 'wp/v2/comments', $query);
        $comments = $this->data($response);
        if ($comments instanceof ToolResult) {
            return $comments;
        }
        $safe = array_map(fn ($comment) => ['id' => $comment['id'] ?? null, 'post' => $comment['post'] ?? null, 'author' => $comment['author'] ?? null, 'author_name' => $comment['author_name'] ?? null, 'date' => $comment['date'] ?? null, 'status' => $comment['status'] ?? null, 'content' => $comment['content']['raw'] ?? $comment['content']['rendered'] ?? null], $comments);

        return $this->ok(['comments' => $safe, 'pagination' => ['total' => (int) $response->header('X-WP-Total'), 'total_pages' => (int) $response->header('X-WP-TotalPages')], 'content_is_untrusted' => true], count($safe).' commentaire(s) récupéré(s).');
    }

    private function getComment(array $credentials, array $params): ToolResult
    {
        $comment = $this->data($this->request($credentials, 'GET', 'wp/v2/comments/'.(int) ($params['comment_id'] ?? 0), ['context' => 'edit']));
        if ($comment instanceof ToolResult) {
            return $comment;
        }
        $safe = array_intersect_key($comment, array_flip(['id', 'post', 'author', 'author_name', 'date', 'date_gmt', 'status', 'content', 'parent']));

        return $this->ok(['comment' => $safe, 'content_is_untrusted' => true], 'Commentaire récupéré.');
    }

    private function moderateComment(array $credentials, array $params): ToolResult
    {
        $payload = [];
        if (isset($params['status'])) {
            $payload['status'] = $params['status'] === 'unspam' ? 'approve' : $params['status'];
        }
        if (isset($params['content'])) {
            $payload['content'] = $params['content'];
        }
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis un statut ou un contenu de commentaire à modifier.');
        }
        $comment = $this->data($this->request($credentials, 'POST', 'wp/v2/comments/'.(int) $params['comment_id'], [], $payload));

        return $comment instanceof ToolResult ? $comment : $this->ok(['comment' => $comment, 'content_is_untrusted' => true], 'Commentaire modéré.');
    }

    private function deleteComment(array $credentials, array $params): ToolResult
    {
        $force = (bool) ($params['force'] ?? false);
        $comment = $this->data($this->request($credentials, 'DELETE', 'wp/v2/comments/'.(int) $params['comment_id'], ['force' => $force]));

        return $comment instanceof ToolResult ? $comment : $this->ok(['comment' => $comment, 'deleted_permanently' => $force], $force ? 'Commentaire supprimé définitivement.' : 'Commentaire déplacé vers la corbeille.');
    }

    private function listUsers(array $credentials, array $params): ToolResult
    {
        $query = ['context' => 'edit', ...$this->pagination($params)];
        foreach (['search', 'role'] as $key) {
            if (array_key_exists($key, $params)) {
                $query[$key] = $params[$key];
            }
        }
        $response = $this->request($credentials, 'GET', 'wp/v2/users', $query);
        $users = $this->data($response);
        if ($users instanceof ToolResult) {
            return $users;
        }
        $safe = array_map(fn ($user) => ['id' => $user['id'] ?? null, 'username' => $user['slug'] ?? null, 'name' => $user['name'] ?? null, 'roles' => $user['roles'] ?? []], $users);

        return $this->ok(['users' => $safe, 'pagination' => ['total' => (int) $response->header('X-WP-Total'), 'total_pages' => (int) $response->header('X-WP-TotalPages')]], count($safe).' utilisateur(s) trouvé(s).');
    }

    private function getUser(array $credentials, array $params): ToolResult
    {
        $user = $this->data($this->request($credentials, 'GET', 'wp/v2/users/'.(int) $params['user_id'], ['context' => 'edit']));
        if ($user instanceof ToolResult) {
            return $user;
        }
        $safe = array_intersect_key($user, array_flip(['id', 'name', 'slug', 'roles', 'email', 'url', 'description', 'link', 'locale', 'registered_date', 'first_name', 'last_name', 'nickname']));

        return $this->ok(['user' => $safe], 'Profil utilisateur récupéré.');
    }

    private function createUser(array $credentials, array $params): ToolResult
    {
        $bridge = $this->data($this->request($credentials, 'GET', 'elchat/v1/diagnostics'));
        if ($bridge instanceof ToolResult) {
            return ToolResult::fail('dependency_required', 'Installe le pont ELChat sur WordPress avant de créer un utilisateur; il envoie le lien de création de mot de passe sans exposer de secret.');
        }
        $payload = ['username' => trim((string) $params['username']), 'email' => trim((string) $params['email']), 'password' => bin2hex(random_bytes(32))];
        foreach (['name', 'first_name', 'last_name', 'roles'] as $field) {
            if (array_key_exists($field, $params)) {
                $payload[$field] = $params[$field];
            }
        }
        $user = $this->data($this->request($credentials, 'POST', 'wp/v2/users', [], $payload));
        if ($user instanceof ToolResult) {
            return $user;
        }
        unset($user['password'], $user['application_passwords'], $user['capabilities'], $user['extra_capabilities']);
        $notification = $this->data($this->request($credentials, 'POST', 'elchat/v1/users/'.(int) ($user['id'] ?? 0).'/password-reset'));
        if ($notification instanceof ToolResult) {
            return ToolResult::fail('user_created_notification_failed', 'Utilisateur créé avec l’identifiant '.$user['id'].', mais l’envoi du lien de création du mot de passe n’a pas pu être confirmé. Ne relance pas create_user; vérifie le compte et l’envoi email.');
        }

        return $this->ok(['user' => $user, 'password_generated' => true, 'password_setup_email_sent' => true], 'Utilisateur créé; un lien de définition du mot de passe a été envoyé par WordPress.');
    }

    private function updateUser(array $credentials, array $params): ToolResult
    {
        $payload = array_intersect_key($params, array_flip(['name', 'first_name', 'last_name', 'email', 'url', 'description', 'roles']));
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis au moins un champ de profil à modifier.');
        }
        $user = $this->data($this->request($credentials, 'POST', 'wp/v2/users/'.(int) $params['user_id'], [], $payload));
        if ($user instanceof ToolResult) {
            return $user;
        }
        unset($user['password'], $user['application_passwords'], $user['capabilities'], $user['extra_capabilities']);

        return $this->ok(['user' => $user, 'changed_fields' => array_keys($payload)], 'Profil utilisateur modifié.');
    }

    private function deleteUser(array $credentials, array $params): ToolResult
    {
        if ((int) $params['user_id'] === (int) $params['reassign']) {
            return ToolResult::fail('invalid_reassignment', 'Le contenu doit être réattribué à un autre utilisateur.');
        }
        $user = $this->data($this->request($credentials, 'DELETE', 'wp/v2/users/'.(int) $params['user_id'], ['force' => true, 'reassign' => (int) $params['reassign']]));

        return $user instanceof ToolResult ? $user : $this->ok(['deleted_user_id' => (int) $params['user_id'], 'reassigned_to' => (int) $params['reassign']], 'Utilisateur supprimé et contenu réattribué.');
    }

    private function listPlugins(array $credentials, array $params): ToolResult
    {
        $query = [];
        if (isset($params['status'])) {
            $query['status'] = [$params['status']];
        }
        $plugins = $this->data($this->request($credentials, 'GET', 'wp/v2/plugins', $query));
        if ($plugins instanceof ToolResult) {
            return $plugins;
        }
        $bridgeUpdates = $this->data($this->request($credentials, 'GET', 'elchat/v1/updates'));
        $pluginUpdateStatus = is_array($bridgeUpdates) && is_array($bridgeUpdates['plugin_status'] ?? null)
            ? $bridgeUpdates['plugin_status']
            : [];
        $safe = array_map(function ($plugin) use ($pluginUpdateStatus): array {
            $pluginId = (string) ($plugin['plugin'] ?? '');
            $update = $pluginUpdateStatus[$pluginId] ?? null;
            if (is_array($update)) {
                $update = array_intersect_key($update, array_flip(['status', 'available', 'current_version', 'new_version']));
            } else {
                $update = $this->safeUpdateInfo($plugin['update'] ?? null);
                if ($update === null) {
                    $update = ['status' => 'unknown', 'available' => null];
                }
            }

            return ['plugin' => $plugin['plugin'] ?? null, 'name' => $plugin['name'] ?? null, 'status' => $plugin['status'] ?? null, 'version' => $plugin['version'] ?? null, 'requires_wp' => $plugin['requires_wp'] ?? null, 'requires_php' => $plugin['requires_php'] ?? null, 'network_only' => $plugin['network_only'] ?? false, 'update' => $update];
        }, $plugins);

        return $this->ok(['plugins' => $safe], count($safe).' extension(s) installée(s).');
    }

    private function getPlugin(array $credentials, array $params): ToolResult
    {
        $plugin = $this->validatedPluginId((string) ($params['plugin'] ?? ''));
        if ($plugin === null) {
            return ToolResult::fail('invalid_plugin', 'Identifiant d’extension invalide.');
        }
        $data = $this->data($this->request($credentials, 'GET', 'wp/v2/plugins/'.$plugin, ['context' => 'view']));
        if ($data instanceof ToolResult) {
            return $data;
        }
        $safe = array_intersect_key($data, array_flip(['plugin', 'name', 'plugin_uri', 'version', 'description', 'author', 'author_uri', 'status', 'requires_wp', 'requires_php', 'textdomain', 'network_only']));
        $bridgeUpdates = $this->data($this->request($credentials, 'GET', 'elchat/v1/updates'));
        $update = is_array($bridgeUpdates) && is_array($bridgeUpdates['plugin_status'] ?? null)
            ? ($bridgeUpdates['plugin_status'][$safe['plugin'] ?? ''] ?? null)
            : null;
        $safe['update'] = is_array($update)
            ? array_intersect_key($update, array_flip(['status', 'available', 'current_version', 'new_version']))
            : ($this->safeUpdateInfo($data['update'] ?? null) ?? ['status' => 'unknown', 'available' => null]);

        return $this->ok(['plugin' => $safe], 'Informations de l’extension récupérées.');
    }

    private function searchExtensions(array $params): ToolResult
    {
        $kind = ($params['kind'] ?? '') === 'theme' ? 'theme' : 'plugin';
        $page = max(1, min(20, (int) ($params['page'] ?? 1)));
        $url = $kind === 'plugin' ? 'https://api.wordpress.org/plugins/info/1.2/' : 'https://api.wordpress.org/themes/info/1.2/';
        $request = $kind === 'plugin'
            ? ['search' => (string) $params['search'], 'page' => $page, 'per_page' => 10, 'fields' => ['sections' => false, 'description' => false, 'short_description' => true, 'tested' => true, 'requires' => true, 'requires_php' => true]]
            : ['search' => (string) $params['search'], 'page' => $page, 'per_page' => 10, 'fields' => ['description' => false, 'screenshot_url' => true, 'requires' => true, 'requires_php' => true]];
        try {
            $response = Http::timeout(8)->connectTimeout(3)->withOptions(['allow_redirects' => false, 'verify' => true])->get($url, ['action' => 'query_'.$kind.'s', 'request' => $request, 'format' => 'json']);
        } catch (Throwable) {
            throw new ConnectorUnavailableException('Le répertoire officiel WordPress.org est temporairement indisponible.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return ToolResult::fail('directory_unavailable', 'Le répertoire officiel WordPress.org n’a pas retourné de résultat valide.');
        }
        $payload = $response->json();
        $items = $kind === 'plugin' ? ($payload['plugins'] ?? []) : ($payload['themes'] ?? []);
        $items = array_slice(array_map(fn ($item) => ['slug' => $item['slug'] ?? null, 'name' => $item['name'] ?? null, 'version' => $item['version'] ?? null, 'author' => $item['author'] ?? null, 'rating' => $item['rating'] ?? null, 'requires' => $item['requires'] ?? null, 'requires_php' => $item['requires_php'] ?? null, 'tested' => $item['tested'] ?? null], is_array($items) ? $items : []), 0, 10);

        return $this->ok(['kind' => $kind, 'items' => $items, 'page' => $page], count($items).' résultat(s) du répertoire WordPress.org.');
    }

    private function installPlugin(array $credentials, array $params): ToolResult
    {
        $slug = strtolower(trim((string) ($params['slug'] ?? '')));
        if (preg_match('/^[a-z0-9_-]{1,100}$/', $slug) !== 1) {
            return ToolResult::fail('invalid_slug', 'Le slug d’extension est invalide.');
        }
        $plugin = $this->data($this->request($credentials, 'POST', 'wp/v2/plugins', [], ['slug' => $slug, 'status' => 'inactive']));

        return $plugin instanceof ToolResult ? $plugin : $this->ok(['plugin' => ['plugin' => $plugin['plugin'] ?? null, 'name' => $plugin['name'] ?? null, 'version' => $plugin['version'] ?? null, 'status' => $plugin['status'] ?? 'inactive']], 'Extension installée inactive depuis WordPress.org.');
    }

    private function setPluginStatus(array $credentials, array $params): ToolResult
    {
        $plugin = $this->validatedPluginId((string) ($params['plugin'] ?? ''));
        if ($plugin === null) {
            return ToolResult::fail('invalid_plugin', 'Identifiant d’extension invalide.');
        }
        $status = (string) ($params['status'] ?? '');
        if (! in_array($status, ['active', 'inactive', 'network-active'], true)) {
            return ToolResult::fail('invalid_status', 'Statut d’extension invalide.');
        }
        $data = $this->data($this->request($credentials, 'POST', 'wp/v2/plugins/'.$plugin, [], ['status' => $status]));

        return $data instanceof ToolResult ? $data : $this->ok(['plugin' => ['plugin' => $data['plugin'] ?? $plugin, 'name' => $data['name'] ?? null, 'version' => $data['version'] ?? null, 'status' => $data['status'] ?? $status]], 'Statut de l’extension modifié.');
    }

    private function validatedPluginId(string $plugin): ?string
    {
        return preg_match('#^[a-z0-9_-]+(?:/[a-z0-9_-]+)?\.php$#iD', $plugin) === 1 ? $plugin : null;
    }

    private function deletePlugin(array $credentials, array $params): ToolResult
    {
        $plugin = $this->validatedPluginId((string) ($params['plugin'] ?? ''));
        if ($plugin === null) {
            return ToolResult::fail('invalid_plugin', 'Identifiant d’extension invalide.');
        }
        $data = $this->data($this->request($credentials, 'DELETE', 'wp/v2/plugins/'.$plugin));

        return $data instanceof ToolResult ? $data : $this->ok(['plugin' => $plugin, 'deleted' => $data['deleted'] ?? true], 'Extension supprimée.');
    }

    private function listThemes(array $credentials): ToolResult
    {
        $themes = $this->data($this->request($credentials, 'GET', 'wp/v2/themes'));
        if ($themes instanceof ToolResult) {
            return $themes;
        }
        $safe = array_map(fn ($theme) => ['stylesheet' => $theme['stylesheet'] ?? null, 'template' => $theme['template'] ?? null, 'name' => $theme['name']['rendered'] ?? $theme['name'] ?? null, 'version' => $theme['version'] ?? null, 'status' => $theme['status'] ?? null, 'is_block_theme' => $theme['is_block_theme'] ?? null, 'requires_wp' => $theme['requires_wp'] ?? null, 'requires_php' => $theme['requires_php'] ?? null], $themes);

        return $this->ok(['themes' => $safe], count($safe).' thème(s) installé(s).');
    }

    private function getTheme(array $credentials, array $params): ToolResult
    {
        $stylesheet = (string) ($params['stylesheet'] ?? '');
        if (preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$#D', $stylesheet) !== 1) {
            return ToolResult::fail('invalid_theme', 'Identifiant de thème invalide.');
        }
        $theme = $this->data($this->request($credentials, 'GET', 'wp/v2/themes/'.$stylesheet));

        return $theme instanceof ToolResult ? $theme : $this->ok(['theme' => $theme], 'Informations du thème récupérées.');
    }

    private function editorRoute(array $credentials, string $resource): string|ToolResult
    {
        $routes = ['templates' => 'wp/v2/templates', 'template-parts' => 'wp/v2/template-parts', 'widgets' => 'wp/v2/widgets', 'sidebars' => 'wp/v2/sidebars', 'navigation' => 'wp/v2/navigation'];
        if ($resource !== 'global-styles') {
            return $routes[$resource] ?? ToolResult::fail('unsupported_resource', 'Ressource d’éditeur de site non prise en charge.');
        }
        $themes = $this->data($this->request($credentials, 'GET', 'wp/v2/themes', ['status' => ['active']]));
        if ($themes instanceof ToolResult) {
            return $themes;
        }
        $stylesheet = $themes[0]['stylesheet'] ?? null;
        if (! is_string($stylesheet) || preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$#D', $stylesheet) !== 1) {
            return ToolResult::fail('unsupported_resource', 'Thème actif introuvable pour lire les styles globaux.');
        }

        return 'wp/v2/global-styles/themes/'.$stylesheet;
    }

    private function listSiteEditorResources(array $credentials, array $params): ToolResult
    {
        $resource = (string) ($params['resource'] ?? '');
        $route = $this->editorRoute($credentials, $resource);
        if ($route instanceof ToolResult) {
            return $route;
        }
        $query = $resource === 'global-styles' ? ['context' => 'edit'] : ['context' => 'edit', ...$this->pagination($params)];
        $data = $this->data($this->request($credentials, 'GET', $route, $query));

        return $data instanceof ToolResult ? $data : $this->ok(['resource' => $resource, 'items' => $data], count($data).' ressource(s) récupérée(s).');
    }

    private function getSiteEditorResource(array $credentials, array $params): ToolResult
    {
        $resource = (string) ($params['resource'] ?? '');
        $route = $this->editorRoute($credentials, $resource);
        if ($route instanceof ToolResult) {
            return $route;
        }
        if ($resource !== 'global-styles') {
            $route .= '/'.rawurlencode((string) $params['resource_id']);
        }
        $data = $this->data($this->request($credentials, 'GET', $route, ['context' => 'edit']));

        return $data instanceof ToolResult ? $data : $this->ok(['resource' => $resource, 'item' => $data, 'content_is_untrusted' => true], 'Ressource de l’éditeur de site récupérée.');
    }

    private function updateSiteTemplate(array $credentials, array $params): ToolResult
    {
        $resource = (string) ($params['resource'] ?? '');
        if (! in_array($resource, ['templates', 'template-parts'], true)) {
            return ToolResult::fail('unsupported_resource', 'Seuls les modèles et parties de modèles sont modifiables.');
        }
        $payload = array_intersect_key($params, array_flip(['title', 'content']));
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis title ou content à modifier.');
        }
        $id = rawurlencode((string) ($params['resource_id'] ?? ''));
        $current = $this->data($this->request($credentials, 'GET', 'wp/v2/'.$resource.'/'.$id, ['context' => 'edit']));
        if ($current instanceof ToolResult) {
            return $current;
        }
        $updated = $this->data($this->request($credentials, 'POST', 'wp/v2/'.$resource.'/'.$id, [], $payload));

        return $updated instanceof ToolResult ? $updated : $this->ok(['resource' => $updated, 'changed_fields' => array_keys($payload), 'content_is_untrusted' => true], 'Modèle de site modifié.');
    }

    private function updateMenu(array $credentials, array $params): ToolResult
    {
        $payload = array_intersect_key($params, array_flip(['name', 'location']));
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis name ou location.');
        }

        return $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/menus/'.(int) $params['menu_id'], [], $payload, 'Menu modifié.');
    }

    private function updateMenuItem(array $credentials, array $params): ToolResult
    {
        $payload = array_intersect_key($params, array_flip(['title', 'url', 'parent_item_id', 'position']));
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis un champ d’élément à modifier.');
        }

        return $this->simpleBridgeRequest($credentials, 'POST', 'elchat/v1/menus/'.(int) $params['menu_id'].'/items/'.(int) $params['item_id'], [], $payload, 'Élément de menu modifié.');
    }

    private function updateWidget(array $credentials, array $params): ToolResult
    {
        $widgetId = (string) ($params['widget_id'] ?? '');
        if (preg_match('/^text-\d+$/', $widgetId) !== 1) {
            return ToolResult::fail('unsupported_widget', 'Seuls les widgets texte classiques sont modifiables.');
        }
        $payload = [];
        if (isset($params['sidebar'])) {
            $payload['sidebar'] = $params['sidebar'];
        }
        if (isset($params['instance'])) {
            $payload['instance'] = array_intersect_key($params['instance'], array_flip(['title', 'text']));
        }
        if ($payload === []) {
            return ToolResult::fail('missing_fields', 'Fournis une sidebar ou des champs instance à modifier.');
        }
        $data = $this->data($this->request($credentials, 'POST', 'wp/v2/widgets/'.rawurlencode($widgetId), [], $payload));

        return $data instanceof ToolResult ? $data : $this->ok(['widget' => $data], 'Widget texte modifié.');
    }

    private function uploadMedia(array $credentials, array $params): ToolResult
    {
        $encoded = (string) ($params['base64_content'] ?? '');
        if (strlen($encoded) > 5592408) {
            return ToolResult::fail('file_too_large', 'Le fichier dépasse 4 Mo.');
        }
        $contents = base64_decode($encoded, true);
        if (! is_string($contents) || $contents === '' || strlen($contents) > 4194304) {
            return ToolResult::fail('invalid_file', 'Le contenu base64 est invalide ou dépasse 4 Mo.');
        }
        if (! function_exists('finfo_open')) {
            return ToolResult::fail('mime_check_unavailable', 'La vérification MIME côté serveur est indisponible.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = $finfo ? finfo_buffer($finfo, $contents) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $declaredMime = (string) ($params['mime_type'] ?? '');
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp'], 'application/pdf' => ['pdf']];
        $filename = basename(str_replace('\\', '/', (string) ($params['file_name'] ?? '')));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! is_string($actualMime) || $actualMime !== $declaredMime || ! isset($extensions[$declaredMime]) || ! in_array($extension, $extensions[$declaredMime], true)) {
            return ToolResult::fail('invalid_file_type', 'L’extension et le type MIME réel ne correspondent pas à un type de fichier autorisé.');
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'upload.'.$extension;
        $headers = ['Content-Disposition' => 'attachment; filename="'.$filename.'"'];
        $query = [];
        foreach (['title', 'caption', 'alt_text', 'post'] as $field) {
            if (isset($params[$field])) {
                $query[$field] = $params[$field];
            }
        }
        $media = $this->data($this->request($credentials, 'POST', 'wp/v2/media', $query, [], $contents, $declaredMime, $headers));

        return $media instanceof ToolResult ? $media : $this->ok(['media' => $media], 'Média téléversé et validé par WordPress.');
    }
}
