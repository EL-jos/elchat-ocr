<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;
use Webklex\PHPIMAP\Address as ImapAddress;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\AuthFailedException as ImapAuthFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Connecteur MCP autonome pour une boîte email professionnelle générique.
 *
 * Ce connecteur ne dépend volontairement d'aucune classe SocialChannels,
 * SocialAccount ou intégration Gmail/Outlook. Il utilise IMAP pour la boîte
 * et SMTP pour les opérations sortantes, avec une configuration propre au
 * site et des identifiants fournis par CredentialVault.
 *
 * Credentials attendus :
 *   email, display_name, imap_username, imap_password,
 *   smtp_username (optionnel : imap_username par défaut),
 *   smtp_password (optionnel : imap_password par défaut)
 *
 * Settings attendus :
 *   imap_host, imap_port, imap_encryption,
 *   smtp_host, smtp_port, smtp_encryption,
 *   default_folder, sent_folder, trash_folder, archive_folder
 */
final class ProfessionalEmailImapConnector extends AbstractConnector
{
    private const SLUG = 'imap_professional_email';

    private const DEFAULT_FOLDER = 'INBOX';

    private const DEFAULT_SENT_FOLDER = 'Sent';

    private const DEFAULT_TRASH_FOLDER = 'Trash';

    private const DEFAULT_ARCHIVE_FOLDER = 'Archive';

    private const MAX_RESULTS = 50;

    private const MAX_BODY_CHARS = 50000;

    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    private const MAX_TOTAL_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    public function slug(): string
    {
        return self::SLUG;
    }

    public function defaultTimeout(): int
    {
        return 20;
    }

    public function authenticate(array $credentials): array
    {
        try {
            return $this->normalizeCredentials($credentials);
        } catch (InvalidArgumentException $exception) {
            throw new AuthExpiredException($exception->getMessage(), previous: $exception);
        }
    }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        $messageReference = [
            'uid' => ['type' => 'integer', 'minimum' => 1, 'description' => 'UID IMAP du message, obtenu avec search_emails.'],
            'folder' => ['type' => 'string', 'description' => 'Dossier contenant le message, défaut : INBOX.'],
        ];

        $outboundFields = [
            'to' => [
                'type' => 'array', 'minItems' => 1, 'maxItems' => 50,
                'items' => ['type' => 'string'],
                'description' => 'Destinataires au format adresse@domaine.tld ou Nom <adresse@domaine.tld>.',
            ],
            'cc' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']],
            'bcc' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']],
            'reply_to' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string']],
            'subject' => ['type' => 'string', 'maxLength' => 998],
            'text_body' => ['type' => 'string', 'maxLength' => self::MAX_BODY_CHARS],
            'html_body' => ['type' => 'string', 'maxLength' => self::MAX_BODY_CHARS],
            'attachments' => [
                'type' => 'array', 'maxItems' => 20,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'filename' => ['type' => 'string', 'maxLength' => 255],
                        'content_base64' => ['type' => 'string'],
                        'content_type' => ['type' => 'string', 'maxLength' => 255],
                    ],
                    'required' => ['filename', 'content_base64'],
                ],
            ],
        ];

        return [
            new ToolSchema(
                self::SLUG,
                'test_connection',
                "Teste l'accès à la boîte email professionnelle configurée en IMAP et SMTP, sans lire ni modifier les messages. Utiliser après une configuration ou pour diagnostiquer une connexion.",
                ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'list_mailboxes',
                "Liste les dossiers disponibles dans la boîte professionnelle (réception, envoyés, brouillons, archives, corbeille et dossiers personnalisés). Ne jamais inventer un chemin de dossier avant d'utiliser cet outil.",
                ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'search_emails',
                'Recherche des emails dans un dossier avec des filtres IMAP : expéditeur, destinataire, objet, texte, dates, non-lu, suivi et pièce jointe. Retourne des UID stables à réutiliser avec get_email ou une action. Les emails sont lus en mode aperçu et ne sont pas marqués comme lus.',
                [
                    'type' => 'object',
                    'properties' => [
                        'folder' => ['type' => 'string', 'description' => 'Dossier à rechercher, défaut : INBOX.'],
                        'from' => ['type' => 'string'],
                        'to' => ['type' => 'string'],
                        'subject' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'since' => ['type' => 'string', 'description' => 'Date ISO ou YYYY-MM-DD incluse.'],
                        'before' => ['type' => 'string', 'description' => 'Date ISO ou YYYY-MM-DD exclue.'],
                        'unread' => ['type' => 'boolean'],
                        'flagged' => ['type' => 'boolean'],
                        'has_attachment' => ['type' => 'boolean'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RESULTS, 'description' => 'Défaut 20, maximum 50.'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page de résultats, défaut 1.'],
                    ],
                ],
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'get_email',
                'Récupère un email précis à partir de son UID et de son dossier. Retourne les en-têtes, le corps texte, éventuellement le HTML et les métadonnées de pièces jointes. La lecture ne marque pas le message comme lu.',
                [
                    'type' => 'object',
                    'properties' => $messageReference + [
                        'include_html' => ['type' => 'boolean', 'description' => 'Retourner aussi le HTML original, défaut false.'],
                    ],
                    'required' => ['uid'],
                ],
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'get_thread',
                "Récupère les messages liés au même fil de discussion à partir d'un email. Utiliser avant une réponse complexe afin de comprendre le contexte, sans marquer les messages comme lus.",
                ['type' => 'object', 'properties' => $messageReference, 'required' => ['uid']],
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'list_attachments',
                "Liste les pièces jointes d'un email avec leur nom, type MIME, taille et identifiant de partie, sans retourner leur contenu binaire.",
                ['type' => 'object', 'properties' => $messageReference, 'required' => ['uid']],
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'mark_email',
                "Modifie le statut IMAP d'un email : lu, non lu, suivi, non suivi, répondu ou non répondu. Utiliser uniquement sur le message identifié par search_emails ou get_email.",
                [
                    'type' => 'object',
                    'properties' => $messageReference + [
                        'action' => ['type' => 'string', 'enum' => ['read', 'unread', 'star', 'unstar', 'answered', 'unanswered']],
                    ],
                    'required' => ['uid', 'action'],
                ],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
            ),
            new ToolSchema(
                self::SLUG,
                'move_email',
                'Déplace un email vers un dossier existant, par exemple Archive, Support ou un dossier client. Vérifier le chemin exact avec list_mailboxes si nécessaire.',
                [
                    'type' => 'object',
                    'properties' => $messageReference + ['destination_folder' => ['type' => 'string']],
                    'required' => ['uid', 'destination_folder'],
                ],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
            ),
            new ToolSchema(
                self::SLUG,
                'trash_email',
                'Déplace un email vers la corbeille configurée. Cette action reste récupérable avec restore_email lorsque le serveur IMAP conserve la corbeille.',
                ['type' => 'object', 'properties' => $messageReference, 'required' => ['uid']],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
            ),
            new ToolSchema(
                self::SLUG,
                'restore_email',
                'Restaure un email depuis la corbeille vers un dossier choisi, par défaut INBOX. Ne supprime jamais définitivement un message.',
                [
                    'type' => 'object',
                    'properties' => $messageReference + ['destination_folder' => ['type' => 'string', 'description' => 'Défaut : INBOX.']],
                    'required' => ['uid'],
                ],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
            ),
            new ToolSchema(
                self::SLUG,
                'save_draft',
                'Crée un brouillon dans le dossier Drafts configuré. Un draft_uid peut être fourni pour remplacer un brouillon existant après avoir sauvegardé sa nouvelle version.',
                [
                    'type' => 'object',
                    'properties' => array_merge($outboundFields, [
                        'folder' => ['type' => 'string', 'description' => 'Dossier des brouillons, défaut : Drafts.'],
                        'draft_uid' => ['type' => 'integer', 'minimum' => 1, 'description' => 'UID du brouillon à remplacer, optionnel.'],
                    ]),
                    'required' => ['to', 'subject'],
                ],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'auto',
            ),
            new ToolSchema(
                self::SLUG,
                'send_email',
                "Envoie un nouvel email depuis l'adresse professionnelle via SMTP. Ne jamais utiliser pour répondre à un email existant : utiliser reply_to_email afin de préserver le fil. Une copie est enregistrée dans le dossier Sent lorsque le serveur le permet.",
                ['type' => 'object', 'properties' => $outboundFields, 'required' => ['to', 'subject']],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
                capability: 'communication.send_email',
            ),
            new ToolSchema(
                self::SLUG,
                'reply_to_email',
                "Répond à un email en conservant In-Reply-To et References pour rester dans le même fil. Par défaut répond uniquement à l'expéditeur ; reply_all peut inclure les destinataires visibles. Une copie est enregistrée dans Sent lorsque possible.",
                [
                    'type' => 'object',
                    'properties' => $messageReference + [
                        'text_body' => ['type' => 'string', 'maxLength' => self::MAX_BODY_CHARS],
                        'html_body' => ['type' => 'string', 'maxLength' => self::MAX_BODY_CHARS],
                        'reply_all' => ['type' => 'boolean', 'description' => 'Inclure les autres destinataires, défaut false.'],
                        'quote_original' => ['type' => 'boolean', 'description' => 'Ajouter le texte original sous la réponse, défaut false.'],
                    ],
                    'required' => ['uid', 'text_body'],
                ],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
                capability: 'communication.send_email',
            ),
            new ToolSchema(
                self::SLUG,
                'forward_email',
                'Transfère un email vers des destinataires choisis, avec ses en-têtes de transfert et ses pièces jointes sélectionnées. Ne jamais transférer automatiquement une pièce jointe confidentielle sans demande explicite.',
                [
                    'type' => 'object',
                    'properties' => $messageReference + [
                        'to' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'string']],
                        'text_body' => ['type' => 'string', 'maxLength' => self::MAX_BODY_CHARS],
                        'html_body' => ['type' => 'string', 'maxLength' => self::MAX_BODY_CHARS],
                        'include_attachments' => ['type' => 'boolean', 'description' => 'Inclure les pièces jointes, défaut true.'],
                        'attachment_part_numbers' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0], 'description' => 'Liste optionnelle de parts à transférer.'],
                    ],
                    'required' => ['uid', 'to', 'text_body'],
                ],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
                capability: 'communication.send_email',
            ),
            new ToolSchema(
                self::SLUG,
                'send_draft',
                'Envoie un brouillon existant via SMTP puis tente de le déplacer dans Sent. Utiliser uniquement après confirmation explicite du contenu et des destinataires.',
                ['type' => 'object', 'properties' => $messageReference, 'required' => ['uid']],
                isWriteAction: true,
                defaultActorScope: 'admin',
                defaultMode: 'confirm',
                defaultConfirmActor: 'admin',
                capability: 'communication.send_email',
            ),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        try {
            $credentials = $this->normalizeCredentials($credentials);
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('not_configured', $exception->getMessage());
        }

        return match ($toolName) {
            'test_connection' => $this->testConnection($credentials),
            'list_mailboxes' => $this->listMailboxes($credentials),
            'search_emails' => $this->searchEmails($params, $credentials),
            'get_email' => $this->getEmail($params, $credentials),
            'get_thread' => $this->getThread($params, $credentials),
            'list_attachments' => $this->listAttachments($params, $credentials),
            'mark_email' => $this->markEmail($params, $credentials),
            'move_email' => $this->moveEmail($params, $credentials),
            'trash_email' => $this->trashEmail($params, $credentials),
            'restore_email' => $this->restoreEmail($params, $credentials),
            'save_draft' => $this->saveDraft($params, $credentials),
            'send_email' => $this->sendEmail($params, $credentials),
            'reply_to_email' => $this->replyToEmail($params, $credentials),
            'forward_email' => $this->forwardEmail($params, $credentials),
            'send_draft' => $this->sendDraft($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour ".self::SLUG.'.'),
        };
    }

    private function testConnection(array $credentials): ToolResult
    {
        try {
            $imapFolders = $this->withImap($credentials, function (Client $client): int {
                return $client->getFolders(false, null, true)->count();
            });

            $this->testSmtpConnection($credentials);
        } catch (Throwable $exception) {
            return $this->technicalFailure('test_connection', $exception);
        }

        return ToolResult::ok(
            ['imap' => true, 'smtp' => true, 'mailbox_count' => $imapFolders],
            'Les connexions IMAP et SMTP fonctionnent.',
        );
    }

    private function listMailboxes(array $credentials): ToolResult
    {
        try {
            $folders = $this->withImap($credentials, fn (Client $client) => $client->getFolders(false, null, true)->map(
                fn (Folder $folder): array => [
                    'path' => $folder->path,
                    'name' => $folder->name,
                    'full_name' => $folder->full_name,
                    'delimiter' => $folder->delimiter,
                    'selectable' => ! $folder->no_select,
                    'has_children' => $folder->has_children,
                ],
            )->values()->all());
        } catch (Throwable $exception) {
            return $this->technicalFailure('list_mailboxes', $exception);
        }

        return ToolResult::ok(['mailboxes' => $folders], count($folders).' dossier(s) disponible(s).');
    }

    private function searchEmails(array $params, array $credentials): ToolResult
    {
        try {
            $limit = max(1, min(self::MAX_RESULTS, (int) ($params['limit'] ?? 20)));
            $page = max(1, (int) ($params['page'] ?? 1));
            $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder');
            $hasAttachment = array_key_exists('has_attachment', $params) ? (bool) $params['has_attachment'] : null;

            $result = $this->withImap($credentials, function (Client $client) use ($params, $folderPath, $limit, $page, $hasAttachment): array {
                $folder = $this->requireFolder($client, $folderPath);
                $query = $folder->query()
                    ->leaveUnread()
                    ->setFetchFlags(true)
                    ->setFetchBody(true)
                    ->fetchOrderDesc();

                if (! empty($params['from'])) {
                    $query->whereFrom((string) $params['from']);
                }
                if (! empty($params['to'])) {
                    $query->whereTo((string) $params['to']);
                }
                if (! empty($params['subject'])) {
                    $query->whereSubject((string) $params['subject']);
                }
                if (! empty($params['text'])) {
                    $query->whereText((string) $params['text']);
                }
                if (! empty($params['since'])) {
                    $query->whereSince($this->parseDate($params['since'], 'since'));
                }
                if (! empty($params['before'])) {
                    $query->whereBefore($this->parseDate($params['before'], 'before'));
                }
                if (($params['unread'] ?? false) === true) {
                    $query->whereUnseen();
                }
                if (($params['flagged'] ?? false) === true) {
                    $query->whereFlagged('');
                }

                $total = $query->count();
                $messages = $query->limit($limit, $page)->get();
                $items = collect($messages)
                    ->filter(fn ($message): bool => $hasAttachment === null || $message->getAttachments()->isNotEmpty() === $hasAttachment)
                    ->map(fn (Message $message): array => $this->serializeMessage($message, includeBody: true, bodyLimit: 600))
                    ->values()->all();

                return ['folder' => $folderPath, 'emails' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('search_emails', $exception);
        }

        return ToolResult::ok($result, count($result['emails']).' email(s) trouvé(s).');
    }

    private function getEmail(array $params, array $credentials): ToolResult
    {
        try {
            $message = $this->withImap($credentials, fn (Client $client) => $this->findMessage(
                $client,
                $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder'),
                $this->requireUid($params),
                true,
            ));
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('get_email', $exception);
        }

        if (! $message) {
            return ToolResult::fail('not_found', 'Email introuvable dans ce dossier.');
        }

        return ToolResult::ok([
            'email' => $this->serializeMessage(
                $message,
                includeBody: true,
                includeHtml: (bool) ($params['include_html'] ?? false),
                bodyLimit: self::MAX_BODY_CHARS,
            ),
        ], 'Email récupéré.');
    }

    private function getThread(array $params, array $credentials): ToolResult
    {
        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials): array {
                $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder');
                $folder = $this->requireFolder($client, $folderPath);
                $message = $this->findMessage($client, $folderPath, $this->requireUid($params), true);
                if (! $message) {
                    return ['messages' => []];
                }

                $sentFolder = $this->findFolder($client, $this->folderPath(null, $credentials, 'sent_folder'));
                $thread = $message->thread($sentFolder ?: $folder, null, $folder)
                    ->take(self::MAX_RESULTS)
                    ->map(fn (Message $item): array => $this->serializeMessage($item, includeBody: true, bodyLimit: 12000))
                    ->values()->all();

                return ['messages' => $thread];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('get_thread', $exception);
        }

        if (empty($result['messages'])) {
            return ToolResult::fail('not_found', 'Email introuvable dans ce dossier.');
        }

        return ToolResult::ok($result, count($result['messages']).' message(s) dans le fil.');
    }

    private function listAttachments(array $params, array $credentials): ToolResult
    {
        try {
            $message = $this->withImap($credentials, fn (Client $client) => $this->findMessage(
                $client,
                $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder'),
                $this->requireUid($params),
                true,
            ));
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('list_attachments', $exception);
        }

        if (! $message) {
            return ToolResult::fail('not_found', 'Email introuvable dans ce dossier.');
        }

        $attachments = $message->getAttachments()->map(fn ($attachment): array => [
            'part_number' => (int) $attachment->getPartNumber(),
            'filename' => (string) ($attachment->getName() ?: $attachment->getFilename()),
            'content_type' => (string) ($attachment->getContentType() ?: 'application/octet-stream'),
            'size' => (int) $attachment->getSize(),
            'disposition' => $attachment->getDisposition(),
        ])->values()->all();

        return ToolResult::ok(['attachments' => $attachments], count($attachments).' pièce(s) jointe(s).');
    }

    private function markEmail(array $params, array $credentials): ToolResult
    {
        $action = (string) ($params['action'] ?? '');
        $flagMap = [
            'read' => ['Seen', true], 'unread' => ['Seen', false],
            'star' => ['Flagged', true], 'unstar' => ['Flagged', false],
            'answered' => ['Answered', true], 'unanswered' => ['Answered', false],
        ];
        if (! isset($flagMap[$action])) {
            return ToolResult::fail('invalid_input', 'Action de marquage inconnue.');
        }

        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials, $flagMap): array {
                $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder');
                $message = $this->findMessage($client, $folderPath, $this->requireUid($params), false);
                if (! $message) {
                    return ['found' => false];
                }

                [$flag, $add] = $flagMap[(string) $params['action']];
                $add ? $message->addFlag($flag) : $message->removeFlag($flag);

                return ['found' => true, 'uid' => (int) $message->getUid(), 'action' => $params['action']];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('mark_email', $exception);
        }

        if (! ($result['found'] ?? false)) {
            return ToolResult::fail('not_found', 'Email introuvable dans ce dossier.');
        }

        return ToolResult::ok($result, 'Statut de l’email mis à jour.');
    }

    private function moveEmail(array $params, array $credentials): ToolResult
    {
        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials): array {
                $source = $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder');
                $destination = trim((string) ($params['destination_folder'] ?? ''));
                if ($destination === '') {
                    throw new InvalidArgumentException('destination_folder est obligatoire.');
                }
                if ($source === $destination) {
                    throw new InvalidArgumentException('Le dossier source et le dossier cible sont identiques.');
                }

                $message = $this->findMessage($client, $source, $this->requireUid($params), false);
                if (! $message) {
                    return ['found' => false];
                }
                $target = $this->requireFolder($client, $destination);
                $client->openFolder($source);
                $moved = $client->getConnection()->moveMessage($target->path, (int) $message->getUid(), null, IMAP::ST_UID)->validatedData();

                return ['found' => (bool) $moved, 'uid' => (int) $message->getUid(), 'folder' => $destination];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('move_email', $exception);
        }

        if (! ($result['found'] ?? false)) {
            return ToolResult::fail('not_found', 'Email introuvable ou déplacement refusé par le serveur.');
        }

        return ToolResult::ok($result, 'Email déplacé.');
    }

    private function trashEmail(array $params, array $credentials): ToolResult
    {
        $params['destination_folder'] = $this->folderPath(null, $credentials, 'trash_folder');

        return $this->moveEmail($params, $credentials);
    }

    private function restoreEmail(array $params, array $credentials): ToolResult
    {
        $params['folder'] = $params['folder'] ?? $this->folderPath(null, $credentials, 'trash_folder');
        $params['destination_folder'] = $params['destination_folder'] ?? $this->folderPath(null, $credentials, 'default_folder');

        return $this->moveEmail($params, $credentials);
    }

    private function saveDraft(array $params, array $credentials): ToolResult
    {
        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials): array {
                $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'draft_folder');
                $folder = $this->requireFolder($client, $folderPath);
                $email = $this->buildEmail($params, $credentials);
                $appended = $folder->appendMessage($email->toString(), ['\\Draft']);
                $oldDraftUid = $params['draft_uid'] ?? null;
                if ($oldDraftUid !== null) {
                    $oldDraft = $this->findMessage($client, $folderPath, $this->requireUid(['uid' => $oldDraftUid]), false);
                    if ($oldDraft) {
                        $client->openFolder($folderPath);
                        $oldDraft->delete(true);
                    }
                }

                return [
                    'folder' => $folderPath,
                    'saved' => (bool) $appended,
                    'replaced_uid' => $oldDraftUid !== null ? (int) $oldDraftUid : null,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('save_draft', $exception);
        }

        return ToolResult::ok($result, 'Brouillon enregistré.');
    }

    private function sendEmail(array $params, array $credentials): ToolResult
    {
        try {
            $email = $this->buildEmail($params, $credentials);
            $this->sendViaSmtp($email, $credentials);
            $sent = $this->saveSentCopy($email, $credentials);
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('send_email', $exception);
        }

        return ToolResult::ok([
            'to' => $this->addressStrings($params['to'] ?? []),
            'subject' => (string) ($params['subject'] ?? ''),
            'saved_to_sent' => $sent,
        ], $sent ? 'Email envoyé et copie enregistrée dans Sent.' : 'Email envoyé ; la copie n’a pas pu être enregistrée dans Sent.');
    }

    private function replyToEmail(array $params, array $credentials): ToolResult
    {
        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials): array {
                $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder');
                $message = $this->findMessage($client, $folderPath, $this->requireUid($params), true);
                if (! $message) {
                    return ['found' => false];
                }

                $to = $this->replyRecipients($message, $credentials, (bool) ($params['reply_all'] ?? false));
                if ($to === []) {
                    throw new InvalidArgumentException('Aucun destinataire de réponse exploitable.');
                }

                $body = (string) ($params['text_body'] ?? '');
                if (($params['quote_original'] ?? false) === true) {
                    $body .= "\n\n> ----- Message original -----\n> ".str_replace("\n", "\n> ", trim($message->getTextBody()));
                }

                $emailParams = [
                    'to' => $to,
                    'subject' => $this->replySubject($this->attributeString($message->getSubject())),
                    'text_body' => $body,
                    'html_body' => $params['html_body'] ?? null,
                ];
                $email = $this->buildEmail($emailParams, $credentials);
                $this->addThreadHeaders($email, $message);
                $this->sendViaSmtp($email, $credentials);
                $sent = $this->saveSentCopy($email, $credentials);

                return ['found' => true, 'uid' => (int) $message->getUid(), 'to' => $to, 'subject' => $emailParams['subject'], 'saved_to_sent' => $sent];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('reply_to_email', $exception);
        }

        if (! ($result['found'] ?? false)) {
            return ToolResult::fail('not_found', 'Email introuvable dans ce dossier.');
        }

        return ToolResult::ok($result, 'Réponse envoyée dans le fil.');
    }

    private function forwardEmail(array $params, array $credentials): ToolResult
    {
        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials): array {
                $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'default_folder');
                $message = $this->findMessage($client, $folderPath, $this->requireUid($params), true);
                if (! $message) {
                    return ['found' => false];
                }

                $body = (string) ($params['text_body'] ?? '');
                $body .= "\n\n---------- Message transféré ----------\n";
                $body .= 'De : '.$this->formatAddressList($message->getFrom())."\n";
                $body .= 'Date : '.$this->dateString($message->getDate())."\n";
                $body .= 'Objet : '.$this->attributeString($message->getSubject())."\n\n";
                $body .= trim($message->getTextBody());

                $emailParams = [
                    'to' => $params['to'],
                    'subject' => $this->forwardSubject($this->attributeString($message->getSubject())),
                    'text_body' => $body,
                    'html_body' => $params['html_body'] ?? null,
                ];
                $email = $this->buildEmail($emailParams, $credentials);

                $includeAttachments = ($params['include_attachments'] ?? true) === true;
                $selectedParts = isset($params['attachment_part_numbers'])
                    ? array_map('intval', (array) $params['attachment_part_numbers'])
                    : null;
                if ($includeAttachments) {
                    $this->attachMessageAttachments($email, $message, $selectedParts);
                }

                $this->sendViaSmtp($email, $credentials);
                $sent = $this->saveSentCopy($email, $credentials);

                return ['found' => true, 'uid' => (int) $message->getUid(), 'to' => $params['to'], 'subject' => $emailParams['subject'], 'saved_to_sent' => $sent];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('forward_email', $exception);
        }

        if (! ($result['found'] ?? false)) {
            return ToolResult::fail('not_found', 'Email introuvable dans ce dossier.');
        }

        return ToolResult::ok($result, 'Email transféré.');
    }

    private function sendDraft(array $params, array $credentials): ToolResult
    {
        try {
            $result = $this->withImap($credentials, function (Client $client) use ($params, $credentials): array {
                $folderPath = $this->folderPath($params['folder'] ?? null, $credentials, 'draft_folder');
                $message = $this->findMessage($client, $folderPath, $this->requireUid($params), true);
                if (! $message) {
                    return ['found' => false];
                }

                $raw = rtrim((string) ($message->getHeader()?->raw ?? ''), "\r\n")."\r\n\r\n".$message->getRawBody();
                $this->sendViaSmtp(new RawMessage($raw), $credentials);

                $saved = false;
                try {
                    $saved = $this->saveSentCopy(new RawMessage($raw), $credentials);
                } catch (Throwable $exception) {
                    Log::warning('MCP professional email: copie du brouillon envoyé non enregistrée', [
                        'host' => $credentials['smtp_host'], 'type' => get_class($exception),
                    ]);
                }

                return ['found' => true, 'uid' => (int) $message->getUid(), 'subject' => $this->attributeString($message->getSubject()), 'saved_to_sent' => $saved];
            });
        } catch (InvalidArgumentException $exception) {
            return ToolResult::fail('invalid_input', $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->technicalFailure('send_draft', $exception);
        }

        if (! ($result['found'] ?? false)) {
            return ToolResult::fail('not_found', 'Brouillon introuvable dans ce dossier.');
        }

        return ToolResult::ok($result, 'Brouillon envoyé.');
    }

    protected function withImap(array $credentials, callable $callback): mixed
    {
        $client = $this->makeImapClient($credentials);
        try {
            $client->connect();
            $result = $callback($client);
            $this->recordSuccess();

            return $result;
        } catch (ImapAuthFailedException $exception) {
            $this->recordFailure();
            throw new AuthExpiredException('Identifiants IMAP invalides ou refusés par le serveur.', previous: $exception);
        } catch (Throwable $exception) {
            $this->recordFailure();
            throw $exception;
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // La fermeture de session ne doit pas masquer le résultat de l'action.
            }
        }
    }

    protected function makeImapClient(array $credentials): Client
    {
        $config = Config::make([
            'default' => 'professional_email',
            'accounts' => [
                'professional_email' => [
                    'host' => $credentials['imap_host'],
                    'port' => $credentials['imap_port'],
                    'protocol' => 'imap',
                    'encryption' => $credentials['imap_encryption'],
                    'validate_cert' => $credentials['validate_cert'],
                    'username' => $credentials['imap_username'],
                    'password' => $credentials['imap_password'],
                    'rfc' => 'RFC822',
                    'authentication' => $credentials['imap_authentication'],
                    'timeout' => $this->defaultTimeout(),
                ],
            ],
            'options' => [
                'delimiter' => $credentials['folder_delimiter'],
                'fetch' => IMAP::FT_PEEK,
                'sequence' => IMAP::ST_UID,
                'fetch_body' => true,
                'fetch_flags' => true,
                'fetch_order' => 'desc',
                'message_key' => 'uid',
                'common_folders' => [
                    'root' => $credentials['default_folder'],
                    'draft' => $credentials['draft_folder'],
                    'sent' => $credentials['sent_folder'],
                    'trash' => $credentials['trash_folder'],
                ],
            ],
        ]);

        return new Client($config);
    }

    private function sendViaSmtp(Email|RawMessage $message, array $credentials): void
    {
        $tls = $credentials['smtp_encryption'] !== 'notls';
        $transport = new EsmtpTransport($credentials['smtp_host'], $credentials['smtp_port'], $tls);
        $transport->setUsername($credentials['smtp_username']);
        $transport->setPassword($credentials['smtp_password']);

        try {
            (new Mailer($transport))->send($message);
            $this->recordSuccess();
        } catch (Throwable $exception) {
            $this->recordFailure();
            throw new ConnectorUnavailableException('Le serveur SMTP a refusé l’envoi.', previous: $exception);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // La connexion SMTP peut déjà être fermée après l'envoi.
            }
        }
    }

    private function saveSentCopy(Email|RawMessage $message, array $credentials): bool
    {
        try {
            return (bool) $this->withImap($credentials, function (Client $client) use ($message, $credentials): bool {
                $folder = $this->requireFolder($client, $credentials['sent_folder']);

                return (bool) $folder->appendMessage($message->toString());
            });
        } catch (Throwable $exception) {
            Log::warning('MCP professional email: copie Sent indisponible', [
                'host' => $credentials['imap_host'], 'type' => get_class($exception),
            ]);

            return false;
        }
    }

    private function buildEmail(array $params, array $credentials): Email
    {
        $to = $this->mimeAddresses($params['to'] ?? []);
        if ($to === []) {
            throw new InvalidArgumentException('Au moins un destinataire est obligatoire.');
        }
        $subject = trim((string) ($params['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('Le sujet est obligatoire.');
        }

        $text = (string) ($params['text_body'] ?? '');
        $html = isset($params['html_body']) && $params['html_body'] !== null ? (string) $params['html_body'] : '';
        if ($text === '' && $html === '') {
            throw new InvalidArgumentException('Le corps texte ou HTML est obligatoire.');
        }

        $email = (new Email)
            ->from(new MimeAddress($credentials['email'], $credentials['display_name']))
            ->to(...$to)
            ->subject($subject);

        if ($text !== '') {
            $email->text($text);
        }
        if ($html !== '') {
            $email->html($html);
        }
        foreach (['cc' => 'cc', 'bcc' => 'bcc', 'reply_to' => 'replyTo'] as $input => $method) {
            if (! empty($params[$input])) {
                $email->{$method}(...$this->mimeAddresses($params[$input]));
            }
        }
        $this->attachInputFiles($email, (array) ($params['attachments'] ?? []));

        return $email;
    }

    private function testSmtpConnection(array $credentials): void
    {
        $transport = new EsmtpTransport(
            $credentials['smtp_host'],
            $credentials['smtp_port'],
            $credentials['smtp_encryption'] !== 'notls',
        );
        $transport->setUsername($credentials['smtp_username']);
        $transport->setPassword($credentials['smtp_password']);

        try {
            // start() ouvre la session et authentifie, mais n'envoie aucun
            // message et ne modifie donc pas la boîte du professionnel.
            $transport->start();
            $this->recordSuccess();
        } catch (Throwable $exception) {
            $this->recordFailure();
            throw new ConnectorUnavailableException('Le serveur SMTP est indisponible ou a refusé l’authentification.', previous: $exception);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // La session peut déjà être fermée après une erreur de handshake.
            }
        }
    }

    private function attachInputFiles(Email $email, array $attachments): void
    {
        $total = 0;
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                throw new InvalidArgumentException('Chaque pièce jointe doit être un objet.');
            }
            $filename = $this->safeFilename($attachment['filename'] ?? '');
            $content = base64_decode((string) ($attachment['content_base64'] ?? ''), true);
            if ($filename === '' || $content === false) {
                throw new InvalidArgumentException('Pièce jointe invalide.');
            }
            $size = strlen($content);
            $total += $size;
            if ($size > self::MAX_ATTACHMENT_BYTES || $total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
                throw new InvalidArgumentException('La taille totale des pièces jointes dépasse la limite autorisée.');
            }
            $email->attach($content, $filename, $attachment['content_type'] ?? 'application/octet-stream');
        }
    }

    private function attachMessageAttachments(Email $email, Message $message, ?array $selectedParts): void
    {
        $total = 0;
        foreach ($message->getAttachments() as $attachment) {
            $partNumber = (int) $attachment->getPartNumber();
            if ($selectedParts !== null && ! in_array($partNumber, $selectedParts, true)) {
                continue;
            }
            $content = (string) $attachment->getContent();
            $size = strlen($content);
            $total += $size;
            if ($size > self::MAX_ATTACHMENT_BYTES || $total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
                throw new InvalidArgumentException('Une ou plusieurs pièces jointes dépassent la limite autorisée.');
            }
            $email->attach(
                $content,
                $this->safeFilename((string) ($attachment->getName() ?: $attachment->getFilename() ?: 'attachment-'.$partNumber)),
                (string) ($attachment->getContentType() ?: 'application/octet-stream'),
            );
        }
    }

    private function addThreadHeaders(Email $email, Message $message): void
    {
        $messageId = trim($this->attributeString($message->getMessageId()));
        $references = trim($this->referencesString($message->getReferences()));
        if ($messageId !== '') {
            $email->getHeaders()->addTextHeader('In-Reply-To', $messageId);
        }
        $allReferences = trim($references.' '.$messageId);
        if ($allReferences !== '') {
            $email->getHeaders()->addTextHeader('References', $allReferences);
        }
    }

    private function replyRecipients(Message $message, array $credentials, bool $replyAll): array
    {
        $values = [];
        foreach ($this->addressObjects($message->getFrom()) as $address) {
            $values[] = $address->mail;
        }
        if ($replyAll) {
            foreach (array_merge($this->addressObjects($message->getTo()), $this->addressObjects($message->getCc())) as $address) {
                if (strcasecmp($address->mail, $credentials['email']) !== 0) {
                    $values[] = $address->mail;
                }
            }
        }

        $seen = [];

        return array_values(array_filter($values, function (string $value) use (&$seen): bool {
            $key = strtolower(trim($value));
            if ($key === '' || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }

    private function findMessage(Client $client, string $folderPath, int $uid, bool $withBody): ?Message
    {
        $folder = $this->requireFolder($client, $folderPath);

        return $folder->query()->leaveUnread()->setFetchBody($withBody)->setFetchFlags(true)->whereUid($uid)->limit(1)->get()->first();
    }

    private function findFolder(Client $client, string $path): ?Folder
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        return $client->getFolderByPath($path, false, true);
    }

    private function requireFolder(Client $client, string $path): Folder
    {
        $folder = $this->findFolder($client, $path);
        if (! $folder || $folder->no_select) {
            throw new InvalidArgumentException("Dossier IMAP introuvable ou non sélectionnable : {$path}.");
        }

        return $folder;
    }

    private function folderPath(?string $requested, array $credentials, string $defaultKey): string
    {
        $path = trim((string) ($requested ?: ($credentials[$defaultKey] ?? self::DEFAULT_FOLDER)));
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException('Le chemin du dossier est invalide.');
        }

        return $path;
    }

    private function requireUid(array $params): int
    {
        $uid = $params['uid'] ?? null;
        if (! is_numeric($uid) || (int) $uid < 1) {
            throw new InvalidArgumentException('UID IMAP invalide.');
        }

        return (int) $uid;
    }

    private function parseDate(mixed $date, string $field): Carbon
    {
        try {
            return Carbon::parse((string) $date);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException("Date {$field} invalide.", previous: $exception);
        }
    }

    private function serializeMessage(Message $message, bool $includeBody, bool $includeHtml = false, int $bodyLimit = 600): array
    {
        $text = $includeBody ? $message->getTextBody() : '';
        $html = $includeBody && $includeHtml ? $message->getHTMLBody() : '';
        $textTruncated = strlen($text) > $bodyLimit;
        $htmlTruncated = strlen($html) > $bodyLimit;
        $data = [
            'uid' => (int) $message->getUid(),
            'folder' => $message->getFolderPath(),
            'message_id' => $this->attributeString($message->getMessageId()),
            'in_reply_to' => $this->attributeString($message->getInReplyTo()),
            'references' => $this->referencesArray($message->getReferences()),
            'subject' => $this->attributeString($message->getSubject()),
            'from' => $this->addressObjects($message->getFrom()),
            'to' => $this->addressObjects($message->getTo()),
            'cc' => $this->addressObjects($message->getCc()),
            'date' => $this->dateString($message->getDate()),
            'flags' => $message->getFlags()->keys()->values()->all(),
            'attachments' => $message->getAttachments()->map(fn ($attachment): array => [
                'part_number' => (int) $attachment->getPartNumber(),
                'filename' => (string) ($attachment->getName() ?: $attachment->getFilename()),
                'content_type' => (string) ($attachment->getContentType() ?: 'application/octet-stream'),
                'size' => (int) $attachment->getSize(),
            ])->values()->all(),
        ];
        if ($includeBody) {
            $data['text_body'] = substr($text, 0, $bodyLimit);
            $data['text_body_truncated'] = $textTruncated;
            if ($includeHtml) {
                $data['html_body'] = substr($html, 0, $bodyLimit);
                $data['html_body_truncated'] = $htmlTruncated;
            }
            $data['preview'] = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        }

        return $data;
    }

    /** @return array<int, array{email:string,name:string}> */
    private function addressObjects(mixed $attribute): array
    {
        $values = $attribute instanceof Attribute ? $attribute->all() : (is_array($attribute) ? $attribute : [$attribute]);

        return array_values(array_filter(array_map(function ($value): ?array {
            if ($value instanceof ImapAddress) {
                return ['email' => $value->mail, 'name' => $value->personal, 'full' => $value->full];
            }
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            try {
                $parsed = MimeAddress::create($value);

                return ['email' => $parsed->getAddress(), 'name' => $parsed->getName(), 'full' => $parsed->toString()];
            } catch (Throwable) {
                return ['email' => $value, 'name' => '', 'full' => $value];
            }
        }, $values)));
    }

    private function mimeAddresses(mixed $input): array
    {
        $values = is_array($input) ? $input : [$input];
        if ($values === [null] || $values === ['']) {
            return [];
        }
        try {
            return MimeAddress::createArray(array_values(array_filter(array_map(fn ($value) => trim((string) $value), $values))));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Une adresse email destinataire est invalide.', previous: $exception);
        }
    }

    private function attributeString(mixed $value): string
    {
        if ($value instanceof Attribute) {
            $first = $value->first();
            if ($first instanceof DateTimeInterface) {
                return $first->format(DATE_ATOM);
            }
            if (is_array($first)) {
                return implode(' ', array_map('strval', $first));
            }

            return trim((string) $value);
        }

        return trim((string) $value);
    }

    private function referencesArray(mixed $value): array
    {
        $values = $value instanceof Attribute ? $value->all() : (array) $value;

        return array_values(array_filter(array_map('strval', $values)));
    }

    private function referencesString(mixed $value): string
    {
        return implode(' ', $this->referencesArray($value));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof Attribute) {
            $value = $value->first();
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function formatAddressList(mixed $value): string
    {
        return implode(', ', array_map(fn (array $address): string => (string) ($address['full'] ?: $address['email']), $this->addressObjects($value)));
    }

    private function addressStrings(mixed $input): array
    {
        return array_values(array_map(fn (string|array $value): string => is_array($value) ? (string) ($value['email'] ?? '') : $value, is_array($input) ? $input : [$input]));
    }

    private function replySubject(string $subject): string
    {
        return preg_match('/^re:\s/i', $subject) ? $subject : 'Re: '.$subject;
    }

    private function forwardSubject(string $subject): string
    {
        return preg_match('/^fwd?:\s/i', $subject) ? $subject : 'Fwd: '.$subject;
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $filename) ?: '';

        return trim($filename, '. ') !== '' ? substr(trim($filename, '. '), 0, 255) : '';
    }

    /** @return array<string, mixed> */
    private function normalizeCredentials(array $credentials): array
    {
        $credentials['email'] = trim((string) ($credentials['email'] ?? ''));
        if (! filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse email professionnelle invalide.');
        }
        $credentials['display_name'] = trim((string) ($credentials['display_name'] ?? ''));
        $credentials['imap_username'] = trim((string) ($credentials['imap_username'] ?? '')) ?: $credentials['email'];
        $credentials['imap_password'] = (string) ($credentials['imap_password'] ?? '');
        if ($credentials['imap_password'] === '') {
            throw new InvalidArgumentException('Le mot de passe IMAP ou mot de passe d’application est obligatoire.');
        }
        $credentials['smtp_username'] = trim((string) ($credentials['smtp_username'] ?? '')) ?: $credentials['imap_username'];
        $credentials['smtp_password'] = (string) ($credentials['smtp_password'] ?? $credentials['imap_password']);

        foreach (['imap_host', 'smtp_host'] as $field) {
            $credentials[$field] = trim((string) ($credentials[$field] ?? ''));
            if ($credentials[$field] === '' || preg_match('/[\x00-\x1F\x7F\s]/', $credentials[$field])) {
                throw new InvalidArgumentException("{$field} est obligatoire et doit être un nom d’hôte valide.");
            }
        }
        foreach (['imap_port' => 993, 'smtp_port' => 587] as $field => $default) {
            $credentials[$field] = (int) ($credentials[$field] ?? $default);
            if ($credentials[$field] < 1 || $credentials[$field] > 65535) {
                throw new InvalidArgumentException("{$field} est invalide.");
            }
        }
        foreach (['imap_encryption' => 'ssl', 'smtp_encryption' => 'starttls'] as $field => $default) {
            $credentials[$field] = strtolower(trim((string) ($credentials[$field] ?? $default)));
            if (! in_array($credentials[$field], ['ssl', 'starttls', 'notls'], true)) {
                throw new InvalidArgumentException("{$field} doit être ssl, starttls ou notls.");
            }
        }
        $credentials['validate_cert'] = filter_var($credentials['validate_cert'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $credentials['imap_authentication'] = $credentials['imap_authentication'] ?? null;
        $credentials['folder_delimiter'] = (string) (($credentials['folder_delimiter'] ?? null) ?: '/');
        if (strlen($credentials['folder_delimiter']) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $credentials['folder_delimiter'])) {
            throw new InvalidArgumentException('folder_delimiter doit être un caractère imprimable.');
        }
        $credentials['default_folder'] = trim((string) ($credentials['default_folder'] ?? self::DEFAULT_FOLDER));
        $credentials['draft_folder'] = trim((string) ($credentials['draft_folder'] ?? 'Drafts'));
        $credentials['sent_folder'] = trim((string) ($credentials['sent_folder'] ?? self::DEFAULT_SENT_FOLDER));
        $credentials['trash_folder'] = trim((string) ($credentials['trash_folder'] ?? self::DEFAULT_TRASH_FOLDER));
        $credentials['archive_folder'] = trim((string) ($credentials['archive_folder'] ?? self::DEFAULT_ARCHIVE_FOLDER));
        foreach (['default_folder', 'draft_folder', 'sent_folder', 'trash_folder', 'archive_folder'] as $field) {
            if ($credentials[$field] === '' || preg_match('/[\x00-\x1F\x7F]/', $credentials[$field])) {
                throw new InvalidArgumentException("{$field} doit être un chemin IMAP imprimable.");
            }
        }

        return $credentials;
    }

    private function technicalFailure(string $operation, Throwable $exception): ToolResult
    {
        if ($exception instanceof AuthExpiredException) {
            throw $exception;
        }
        Log::warning('MCP professional email: opération échouée', [
            'operation' => $operation,
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        return ToolResult::fail('connector_unavailable', 'Le serveur email professionnel est indisponible ou a refusé l’opération.');
    }
}
