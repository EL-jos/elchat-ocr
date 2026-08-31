# Microsoft 365 dans ELChat

Le module Microsoft 365 est un connecteur MCP unique qui parle à Microsoft Graph depuis le backend. Il couvre les ressources Cloud/Web autorisées par le compte connecté : OneDrive, bibliothèques SharePoint, Excel, Outlook et Teams.

## Architecture par applications

Le connecteur est organisé comme Odoo : le connecteur principal route vers des modules applicatifs indépendants. Chaque module possède son catalogue d’outils, ses règles d’accès et son exécution.

| Module | Outils principaux |
|---|---|
| Fichiers OneDrive et SharePoint | recherche, lecture, téléchargement, dépôt, partage, déplacement et suppression |
| Excel | sessions, feuilles, plages, tableaux et lignes |
| Word | lecture des métadonnées et création de vrais fichiers `.docx` |
| PowerPoint | création structurée, ajout de diapositives, liste, export PDF, renommage, partage, suppression et dépôt de vrais fichiers `.pptx` |
| Outlook | recherche, lecture, brouillons et envoi confirmé |
| Calendrier Outlook | lecture, création, modification et suppression d’événements |
| Contacts Outlook | recherche, lecture et création de contacts |
| To Do | listes et tâches personnelles |
| Microsoft Lists | listes SharePoint, éléments et champs |
| OneNote | blocs-notes, sections, pages et création HTML |
| Teams | équipes, canaux, messages et publication confirmée |

Sway, Microsoft Loop, Microsoft Forms et Power Automate sont également répertoriés comme modules dans le catalogue. Ils restent explicitement marqués comme indisponibles dans ce connecteur : Sway et Loop n’ont pas d’API Graph publique stable adaptée, Forms est accessible officiellement via son connecteur Power Automate, et Power Automate nécessite une connexion Power Platform distincte.

Pour activer les outils To Do et OneNote, ajouter dans l’application Entra les permissions déléguées `Tasks.Read` / `Tasks.ReadWrite` et `Notes.Read` / `Notes.ReadWrite`, puis reconnecter Microsoft 365. Microsoft Lists utilise les permissions SharePoint déjà configurées (`Sites.Read.All` / `Sites.ReadWrite.All`).

Pour ajouter un outil, il suffit de l’ajouter au module applicatif concerné : le registre du connecteur, le catalogue des capacités et le contrôle des autorisations le récupèrent automatiquement. Les anciens noms d’outils restent compatibles avec les capacités et workflows existants.

## Mise en service

Configurer côté backend :

```dotenv
APP_URL=https://elchat.io
FRONTEND_DASHBOARD_URL=https://elchat.io
MICROSOFT_CLIENT_ID=...
MICROSOFT_CLIENT_SECRET=...
MICROSOFT_TENANT=common
```

`APP_URL` garantit que Laravel construit le callback sur le domaine public. `FRONTEND_DASHBOARD_URL` garantit que la popup envoie le `postMessage` vers l’origine du dashboard de production.

### Valeurs à renseigner dans Microsoft Entra / Azure

Dans **Microsoft Entra ID → App registrations → l’application → Branding & properties**, renseigner :

| Champ Azure | Valeur de production |
|---|---|
| URL de la page d’accueil | `https://elchat.io/` |
| URL des conditions de service | `https://elchat.io/conditions-generales-d-utilisation` |
| URL de la déclaration de confidentialité | `https://elchat.io/politique-de-confidentialite` |
| Informations de référence sur le management des services | `https://elchat.io/mentions-legales` |
| Domaine de l’éditeur | `elchat.io` |

Dans **Authentication → Platform configurations → Web → Redirect URIs**, ajouter exactement :

```text
https://elchat.io/mcp/connectors/microsoft_365/oauth/callback
```

Cette URI est le callback Laravel côté serveur, pas une URL Angular et pas l’URL de la page d’accueil. Le code la génère avec la route `mcp.oauth.callback` ; elle doit donc rester strictement identique à l’URI déclarée dans Azure. En local, ajouter séparément l’URI de l’environnement local si nécessaire, sans la mélanger à la production.

Le callback utilise un état opaque à usage unique, conservé dix minutes côté serveur. Après l’autorisation, la page popup existante `mcp/oauth-popup` envoie un message `mcp_oauth` à la fenêtre du dashboard avec `window.opener.postMessage(...)`, puis se ferme. Aucun nouveau fichier n’est ajouté dans `backend/resources/views/*`.

La connexion utilise `openid offline_access https://graph.microsoft.com/.default`. Microsoft Entra prend ainsi directement toutes les permissions Microsoft Graph déclarées et consenties sur l’inscription de l’application ELChat ; aucun profil de permissions n’est choisi dans le dashboard. Le bouton explicite « Actualiser les autorisations » ajoute `prompt=consent` afin de reprendre en compte une permission statique ajoutée depuis le dernier consentement. Les credentials, refresh tokens et réponses OAuth restent chiffrés dans `mcp_site_connectors.credentials_encrypted`.

### Export HubSpot vers Excel

L’outil `hubspot__export_contacts_to_excel` exporte tous les contacts accessibles par le token HubSpot vers un nouveau fichier `.xlsx` dans le OneDrive de l’utilisateur Microsoft 365 connecté. Il est réservé à l’administrateur et demande une confirmation avant création du fichier. Le dossier de destination peut être la racine OneDrive ou un dossier Graph précisé par son identifiant.

Pour cet export, l’inscription Microsoft Entra doit contenir `Files.ReadWrite` et le consentement du tenant doit être accordé. Après un changement de permissions, reconnecter Microsoft 365 dans ELChat afin d’obtenir un nouveau token basé sur `/.default`. L’export concerne les contacts auxquels le token HubSpot a accès, et non les fiches que HubSpot masque à cette application.

La synchronisation OneDrive utilise `/me/drive/root/delta`. Un `403` sur cette route indique généralement un consentement obsolète/incomplet, l’absence de `Files.Read` dans le token, un OneDrive Entreprise non provisionné ou une règle du tenant. Les permissions `Sites.Read.*` ne remplacent pas les permissions `Files.*` pour le OneDrive personnel de l’utilisateur. Vérifier la licence et le provisionnement OneDrive, puis reconnecter Microsoft 365.

Pour les outils Teams, ajouter aussi dans Azure les permissions déléguées `Team.ReadBasic.All`, `Channel.ReadBasic.All` et `ChannelMessage.Read.All`; `ChannelMessage.Send` est nécessaire pour publier un message. Ces permissions sont indépendantes de l’export Excel.

## Isolation et permissions

Un enregistrement de connexion est attaché à un `Site` ELChat et conserve séparément le tenant Microsoft, le principal connecté et les scopes retournés par Microsoft. Une connexion d’un autre site n’est jamais réutilisée. Les outils Microsoft 365 sont exposés à partir du catalogue de l’application ; Microsoft Graph reste l’autorité finale lors de l’appel, puis `mcp_permissions` contrôle l’autorisation métier ELChat.

Les mutations sensibles (partage, liens, suppression, copie/déplacement, écriture Excel, publication Teams et envoi Outlook) sont confirmées par le mécanisme HITL existant. Une confirmation réévalue toujours le mode de permission, le périmètre de l’acteur et le tenant connecté ; elle ne constitue pas un contournement.

Les fichiers jusqu’à 4 Mo utilisent l’upload Graph direct. Au-delà, le connecteur utilise une vraie session d’upload Graph par blocs (jusqu’à 512 Mo via l’outil MCP), sans transmettre le bearer token à l’URL pré-authentifiée de session.

## Synchronisation RAG

`POST /api/v1/site/{site}/mcp/microsoft-365/sync` met une synchronisation delta en file d’attente. Le scheduler la relance périodiquement pour les connexions actives. Les curseurs sont dans `microsoft365_sync_cursors` et les éléments vus dans `microsoft365_sources`.

Les octets téléchargés pour indexation vont sur le disque privé Laravel, jamais sous `public/`. Les chunks portent l’origine Microsoft et sont exclus du RAG lorsqu’aucun acteur back-office n’a été explicitement propagé. La recherche publique reste donc fail-closed ; un administrateur peut interroger ces sources via le pipeline existant.

Les formats indexés par le pipeline documentaire existant sont PDF, Word, Excel, CSV et texte. PowerPoint est manipulé comme un vrai document Office Open XML : `powerpoint_create_presentation` génère des diapositives avec titres et contenu adapté à la demande via PHPPresentation, et `powerpoint_add_slide` télécharge, modifie puis ré-envoie le `.pptx`. Chaque diapositive doit contenir au moins une puce ; un appel avec des titres seuls est rejeté afin que l’agent transmette le contenu correspondant au sujet demandé. L’export PDF utilise la conversion officielle Microsoft Graph. Excel expose les APIs workbook Graph, notamment feuilles, plages, tableaux, lignes et sessions persistantes.

## Compatibilité

Les connecteurs historiques `onedrive` et `microsoft_teams` restent présents. OneDrive réutilise désormais le client Graph partagé ; le webhook Teams existant reste une voie séparée. Le Marketplace présente `microsoft_365` comme connexion Graph unifiée, sans supprimer les anciennes connexions.

## Tests

```bash
cd backend
php artisan test --filter=Microsoft365
cd ../frontend/dashboard
npm run build -- --configuration production
```

Les tests unitaires utilisent uniquement des réponses Graph simulées ; aucun compte Microsoft réel n’est requis.
