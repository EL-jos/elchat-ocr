<?php

namespace App\Services;

use App\Domain\MCP\Connectors\Microsoft365Connector;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\Microsoft365\Exceptions\MicrosoftGraphException;
use App\Domain\Microsoft365\MicrosoftGraphClient;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exporte les contacts accessibles par HubSpot dans un vrai classeur .xlsx
 * stocké dans le OneDrive de l'utilisateur Microsoft 365 connecté.
 *
 * Le fichier est construit côté serveur afin de ne pas demander au modèle de
 * fabriquer ou de transporter un classeur binaire en base64.
 */
final class HubSpotExcelExportService
{
    private const HUBSPOT_CONTACT_SEARCH = 'https://api.hubapi.com/crm/v3/objects/contacts/search';
    private const MAX_CONTACTS = 50000;
    private const PAGE_SIZE = 200;
    private const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var list<string> */
    private const PROPERTIES = [
        'firstname', 'lastname', 'email', 'phone', 'company', 'address', 'website',
        'lifecyclestage', 'hs_lead_status', 'createdate', 'lastmodifieddate',
    ];

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly Microsoft365Connector $microsoft365,
    ) {
    }

    /**
     * @param array<string, mixed> $hubspotCredentials
     * @param array<string, mixed> $params
     */
    public function export(Site $site, array $hubspotCredentials, array $params = []): ToolResult
    {
        if (empty($hubspotCredentials['access_token'])) {
            return ToolResult::fail('hubspot_auth', 'La connexion HubSpot est absente ou expirée.');
        }

        $microsoftCredentials = $this->vault->retrieve($site, 'microsoft_365');
        if (!$microsoftCredentials || empty($microsoftCredentials['access_token'])) {
            return ToolResult::fail(
                'microsoft_not_connected',
                'Connectez d’abord Microsoft 365 au site pour créer le fichier Excel dans OneDrive.'
            );
        }

        try {
            $freshCredentials = $this->microsoft365->authenticate($microsoftCredentials);
            if ($freshCredentials !== $microsoftCredentials) {
                $this->vault->refresh($site, 'microsoft_365', $freshCredentials);
            }

            $maxContacts = min(
                self::MAX_CONTACTS,
                max(1, (int) ($params['max_contacts'] ?? self::MAX_CONTACTS))
            );
            $contacts = $this->fetchAllContacts($hubspotCredentials, $maxContacts);
            if ($contacts['truncated']) {
                return ToolResult::fail(
                    'too_many_contacts',
                    'L’export a été interrompu car le nombre de contacts dépasse la limite de sécurité de ' . number_format($maxContacts, 0, ',', ' ') . '. Aucun fichier partiel n’a été créé.'
                );
            }

            $name = $this->filename($params['name'] ?? null);
            $content = $this->buildWorkbook($contacts['contacts']);
            $file = $this->upload($freshCredentials, $params, $name, $content);

            $summary = sprintf('%d prospect(s) HubSpot exporté(s) dans « %s ».', count($contacts['contacts']), $name);
            if (!empty($file['webUrl'])) {
                $summary .= ' Le fichier est disponible dans Microsoft 365.';
            }

            return ToolResult::ok([
                'contacts_count' => count($contacts['contacts']),
                'file' => array_filter([
                    'id' => $file['id'] ?? null,
                    'name' => $file['name'] ?? $name,
                    'size' => $file['size'] ?? strlen($content),
                    'webUrl' => $file['webUrl'] ?? null,
                    'lastModifiedDateTime' => $file['lastModifiedDateTime'] ?? null,
                ], static fn ($value) => $value !== null),
            ], $summary);
        } catch (AuthExpiredException) {
            return ToolResult::fail(
                'microsoft_auth_expired',
                'La session Microsoft 365 a expiré. Reconnectez Microsoft 365 puis relancez l’export.'
            );
        } catch (RequestException $exception) {
            $status = $exception->response?->status();
            if (in_array($status, [401, 403], true)) {
                return ToolResult::fail(
                    'hubspot_auth',
                    'HubSpot a refusé la lecture des contacts. Vérifiez le token de l’application privée et son scope crm.objects.contacts.read.'
                );
            }

            Log::warning('Export HubSpot vers Excel refusé par HubSpot', [
                'site_id' => $site->id,
                'status' => $status,
            ]);

            return ToolResult::fail('hubspot_error', 'HubSpot n’a pas pu fournir les contacts à exporter.');
        } catch (ConnectionException) {
            return ToolResult::fail('hubspot_unavailable', 'HubSpot est momentanément inaccessible. Réessayez dans quelques instants.');
        } catch (MicrosoftGraphException $exception) {
            if ($exception->isAuthFailure()) {
                return ToolResult::fail(
                    'microsoft_auth_expired',
                    'La session Microsoft 365 a expiré. Reconnectez Microsoft 365 puis relancez l’export.'
                );
            }

            if ($exception->status === 403) {
                return ToolResult::fail(
                    'microsoft_forbidden',
                    'Microsoft 365 a refusé l’écriture dans OneDrive. Vérifiez Files.ReadWrite, le consentement de l’application et la disponibilité de OneDrive Entreprise pour l’utilisateur connecté.'
                );
            }

            Log::warning('Export HubSpot vers Excel refusé par Microsoft Graph', [
                'site_id' => $site->id,
                'status' => $exception->status,
                'graph_code' => $exception->graphCode,
            ]);

            return ToolResult::fail('microsoft_error', 'Microsoft 365 n’a pas pu créer le fichier Excel.');
        } catch (\Throwable $exception) {
            Log::error('Export HubSpot vers Excel échoué', [
                'site_id' => $site->id,
                'exception' => $exception::class,
            ]);

            return ToolResult::fail('export_failed', 'L’export des prospects a échoué. Aucun résultat partiel n’a été retourné.');
        }
    }

    /**
     * @return array{contacts: list<array<string, mixed>>, truncated: bool}
     */
    private function fetchAllContacts(array $credentials, int $maxContacts): array
    {
        $request = Http::withToken((string) $credentials['access_token'])
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(10);
        $contacts = [];
        $after = null;

        do {
            $body = [
                'limit' => self::PAGE_SIZE,
                'properties' => self::PROPERTIES,
            ];
            if ($after !== null) {
                $body['after'] = $after;
            }

            $response = $request->post(self::HUBSPOT_CONTACT_SEARCH, $body)->throw();
            $payload = $response->json();
            $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

            foreach ($results as $contact) {
                if (count($contacts) >= $maxContacts) {
                    return ['contacts' => [], 'truncated' => true];
                }

                $contacts[] = [
                    'id' => (string) ($contact['id'] ?? ''),
                    'properties' => is_array($contact['properties'] ?? null) ? $contact['properties'] : [],
                ];
            }

            $nextAfter = $payload['paging']['next']['after'] ?? null;
            $after = $nextAfter === null || $nextAfter === '' ? null : (string) $nextAfter;
        } while ($after !== null);

        return ['contacts' => $contacts, 'truncated' => false];
    }

    /** @param list<array<string, mixed>> $contacts */
    private function buildWorkbook(array $contacts): string
    {
        $headers = [
            'ID HubSpot', 'Prénom', 'Nom', 'Email', 'Téléphone', 'Entreprise', 'Adresse',
            'Site web', 'Étape du cycle de vie', 'Statut du lead', 'Date de création', 'Dernière modification',
        ];
        $rows = [$headers];

        foreach ($contacts as $contact) {
            $properties = $contact['properties'] ?? [];
            $rows[] = [
                $this->cell($contact['id'] ?? ''),
                $this->cell($properties['firstname'] ?? ''),
                $this->cell($properties['lastname'] ?? ''),
                $this->cell($properties['email'] ?? ''),
                $this->cell($properties['phone'] ?? ''),
                $this->cell($properties['company'] ?? ''),
                $this->cell($properties['address'] ?? ''),
                $this->cell($properties['website'] ?? ''),
                $this->cell($properties['lifecyclestage'] ?? ''),
                $this->cell($properties['hs_lead_status'] ?? ''),
                $this->cell($properties['createdate'] ?? ''),
                $this->cell($properties['lastmodifieddate'] ?? ''),
            ];
        }

        $spreadsheet = new Spreadsheet();
        $temporaryPath = null;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Prospects');
            $sheet->fromArray($rows, null, 'A1');

            $lastColumn = $this->columnName(count($headers));
            $lastRow = count($rows);
            $headerRange = "A1:{$lastColumn}1";

            $sheet->getStyle($headerRange)->getFont()->setBold(true);
            $sheet->getStyle($headerRange)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE2E8F0');
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

            for ($column = 1; $column <= count($headers); $column++) {
                $sheet->getColumnDimension($this->columnName($column))->setAutoSize(true);
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'elchat-hubspot-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('Impossible de créer le fichier temporaire Excel.');
            }

            (new Xlsx($spreadsheet))->save($temporaryPath);
            $content = file_get_contents($temporaryPath);
            if ($content === false) {
                throw new \RuntimeException('Impossible de lire le fichier temporaire Excel.');
            }

            return $content;
        } finally {
            $spreadsheet->disconnectWorksheets();
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /** @return array<string, mixed> */
    private function upload(array $credentials, array $params, string $name, string $content): array
    {
        $prefix = !empty($params['drive_id'])
            ? '/drives/' . rawurlencode(trim((string) $params['drive_id']))
            : '/me/drive';
        $parent = !empty($params['parent_item_id'])
            ? 'items/' . rawurlencode(trim((string) $params['parent_item_id']))
            : 'root';
        $path = $prefix . '/' . $parent . ':/' . rawurlencode($name) . ':/content';
        $graph = MicrosoftGraphClient::forToken((string) $credentials['access_token']);

        if (strlen($content) <= 4 * 1024 * 1024) {
            return $graph->putContent($path, $content, self::MIME_TYPE);
        }

        $session = $graph->post(str_replace(':/content', ':/createUploadSession', $path), [
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'replace',
                'name' => $name,
            ],
        ]);
        if (empty($session['uploadUrl'])) {
            throw new \RuntimeException('Microsoft 365 n’a pas fourni de session d’upload.');
        }

        return $graph->uploadLarge((string) $session['uploadUrl'], $content, self::MIME_TYPE);
    }

    private function filename(mixed $requested): string
    {
        $fallback = 'prospects-hubspot-' . now()->format('Y-m-d-His') . '.xlsx';
        $name = is_string($requested) ? trim($requested) : '';
        if ($name === '') {
            return $fallback;
        }

        $name = str_replace(['/', '\\'], '-', $name);
        $name = preg_replace('/[^\\p{L}\\p{N}._ -]/u', '-', $name) ?: '';
        $name = trim(function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120));
        if ($name === '') {
            return $fallback;
        }

        return str_ends_with(strtolower($name), '.xlsx') ? $name : $name . '.xlsx';
    }

    private function cell(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $formulaCandidate = ltrim($value);
        if ($formulaCandidate !== '' && in_array($formulaCandidate[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $column = intdiv($column - 1, 26);
        }

        return $name;
    }
}
