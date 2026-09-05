<?php

namespace App\Domain\Microsoft365;

/**
 * Source unique des scopes Microsoft Graph que l'application peut demander.
 *
 * L'application ELChat est configurée avec un jeu de permissions Microsoft
 * Graph commun à tous les tenants. Le flux OAuth utilise donc /.default :
 * Microsoft Entra reprend directement les permissions statiques déclarées sur
 * l'inscription de l'application, au lieu de demander un profil différent à
 * chaque connexion.
 */
final class Microsoft365ScopeCatalog
{
    public const USER = 'User.Read';
    public const OFFLINE = 'offline_access';
    public const OPENID = 'openid';
    public const GRAPH_DEFAULT = 'https://graph.microsoft.com/.default';

    private const PROFILES = [
        'documents_read' => ['Files.Read', 'Sites.Read.All'],
        'documents_write' => ['Files.ReadWrite', 'Sites.ReadWrite.All'],
        'excel_read' => ['Files.Read'],
        'excel_write' => ['Files.ReadWrite'],
        'powerpoint_read' => ['Files.Read'],
        'powerpoint_write' => ['Files.ReadWrite'],
        'outlook_read' => ['Mail.ReadBasic'],
        'outlook_content_read' => ['Mail.Read'],
        'outlook_write' => ['Mail.ReadWrite', 'Mail.Send'],
        'calendar_read' => ['Calendars.Read'],
        'calendar_write' => ['Calendars.ReadWrite'],
        'contacts_read' => ['Contacts.Read'],
        'contacts_write' => ['Contacts.ReadWrite'],
        'lists_read' => ['Sites.Read.All'],
        'lists_write' => ['Sites.ReadWrite.All'],
        'todo_read' => ['Tasks.Read'],
        'todo_write' => ['Tasks.ReadWrite'],
        'onenote_read' => ['Notes.Read'],
        'onenote_write' => ['Notes.ReadWrite'],
        'teams_read' => ['Team.ReadBasic.All', 'Channel.ReadBasic.All', 'ChannelMessage.Read.All'],
        'teams_write' => ['Team.ReadBasic.All', 'ChannelMessage.Send'],
    ];

    private const CAPABILITIES = [
        'documents.search' => ['documents_read'],
        'documents.read' => ['documents_read'],
        'documents.download' => ['documents_read'],
        'documents.create' => ['documents_write'],
        'documents.create_folder' => ['documents_write'],
        'documents.update' => ['documents_write'],
        'documents.move' => ['documents_write'],
        'documents.copy' => ['documents_write'],
        'documents.delete' => ['documents_write'],
        'documents.share' => ['documents_write'],
        'sharepoint.read' => ['documents_read'],
        'excel.read' => ['excel_read'],
        'excel.write' => ['excel_write'],
        'powerpoint.read' => ['powerpoint_read'],
        'powerpoint.list_presentations' => ['powerpoint_read'],
        'powerpoint.create_presentation' => ['powerpoint_write'],
        'powerpoint.add_slide' => ['powerpoint_write'],
        'powerpoint.upload' => ['powerpoint_write'],
        'powerpoint.export_to_pdf' => ['powerpoint_write'],
        'powerpoint.delete_presentation' => ['powerpoint_write'],
        'powerpoint.rename_presentation' => ['powerpoint_write'],
        'powerpoint.share_presentation' => ['powerpoint_write'],
        'outlook.search' => ['outlook_read'],
        'outlook.read' => ['outlook_content_read'],
        'outlook.draft' => ['outlook_write'],
        'outlook.send' => ['outlook_write'],
        'calendar.read' => ['calendar_read'],
        'calendar.create_event' => ['calendar_write'],
        'calendar.update_event' => ['calendar_write'],
        'calendar.delete_event' => ['calendar_write'],
        'contacts.read' => ['contacts_read'],
        'contacts.create' => ['contacts_write'],
        'lists.read' => ['lists_read'],
        'lists.create_item' => ['lists_write'],
        'lists.update_item' => ['lists_write'],
        'lists.delete_item' => ['lists_write'],
        'todo.read' => ['todo_read'],
        'todo.create_list' => ['todo_write'],
        'todo.create_task' => ['todo_write'],
        'todo.update_task' => ['todo_write'],
        'todo.delete_task' => ['todo_write'],
        'onenote.read' => ['onenote_read'],
        'onenote.create_page' => ['onenote_write'],
        'teams.read' => ['teams_read'],
        'teams.send' => ['teams_write'],
    ];

    /** @return string[] */
    public static function profiles(): array
    {
        return array_keys(self::PROFILES);
    }

    /** @return string[] */
    public static function capabilities(): array
    {
        return array_keys(self::CAPABILITIES);
    }

    /** @return string[] */
    public static function scopesForProfiles(array $profiles): array
    {
        $scopes = [self::USER, self::OFFLINE, self::OPENID];

        foreach ($profiles as $profile) {
            foreach (self::PROFILES[$profile] ?? [] as $scope) {
                $scopes[] = $scope;
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Autorisations statiques de l'inscription ELChat dans Microsoft Entra.
     * /.default demande à Microsoft toutes les permissions Graph configurées
     * et consenties sur l'application, quel que soit le tenant connecté.
     *
     * @return string[]
     */
    public static function applicationScopes(): array
    {
        return [self::OPENID, self::OFFLINE, self::GRAPH_DEFAULT];
    }

    /** @return string[] */
    public static function scopesForCapabilities(array $capabilities): array
    {
        $profiles = [];

        foreach ($capabilities as $capability) {
            $profiles = array_merge($profiles, self::CAPABILITIES[$capability] ?? []);
        }

        return self::scopesForProfiles(array_values(array_unique($profiles)));
    }

    public static function isValidProfile(string $profile): bool
    {
        return array_key_exists($profile, self::PROFILES);
    }

    public static function isValidCapability(string $capability): bool
    {
        return array_key_exists($capability, self::CAPABILITIES);
    }

    /** @return array<string, array<int, string>> */
    public static function describe(): array
    {
        return self::PROFILES;
    }
}
