<?php

namespace App\Services\Social\Email;

use App\Jobs\social\ImapProcessEventJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialEvent;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class ImapSyncService
{
    public function sync(SocialAccount $account): void
    {
        $imap = $account->metadata['imap'] ?? null;

        if (!$imap) {
            Log::warning('[IMAP] Config manquante', ['account_id' => $account->id]);
            return;
        }

        $client = $this->buildClient($account->metadata);

        try {
            $client->connect();

            $inbox   = $client->getFolder('INBOX');
            $lastUid = (int) ($account->sync_cursor ?? 0);

            $messages = $inbox->query()
                ->since(now()->subHours(24))
                ->get();

            $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];

            foreach ($messages as $msg) {

                $uid = (int) $msg->getUid();

                if ($uid <= $lastUid) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    $messageId = (string) ($msg->getMessageId() ?? 'imap-' . $uid);

                    // ✅ Déduplication via external_event_id
                    $hash = hash('sha256', $account->id . ':imap:' . $uid);

                    $alreadyExists = SocialEvent::where('provider', 'imap')
                        ->where('external_event_id', $hash)
                        ->where('social_account_id', $account->id)
                        ->exists();

                    if ($alreadyExists) {
                        $stats['skipped']++;
                        if ($uid > $lastUid) {
                            $account->update(['sync_cursor' => (string) $uid]);
                            $lastUid = $uid;
                        }
                        continue;
                    }

                    // ✅ Créer le SocialEvent (comme pour Facebook/YouTube)
                    $event = SocialEvent::create([
                        'social_account_id'  => $account->id,
                        'provider'           => 'imap',
                        'event_type'         => 'email_received',
                        'external_event_id'  => $hash,
                        'processing_status'  => 'pending',
                        'payload' => [
                            'uid'         => $uid,
                            'message_id'  => $messageId,
                            'subject'     => (string) $msg->getSubject(),
                            'from_email'  => $msg->getFrom()[0]?->mail     ?? null,
                            'from_name'   => $msg->getFrom()[0]?->personal ?? null,
                            'to'          => $msg->getTo()[0]?->mail       ?? null,
                            'body'        => $msg->getTextBody()
                                ?? strip_tags($msg->getHTMLBody() ?? ''),
                            'date'        => $msg->getDate()->toString(),
                            'in_reply_to' => $msg->getHeader()->get('in-reply-to')?->first() ?? null,
                            'attachments' => $this->extractImageAttachments($msg), // 🆕
                        ],
                        'metadata' => [
                            'received_at' => now()->toISOString(),
                            'sync_source' => 'imap_polling',
                        ],
                    ]);

                    // ✅ Dispatcher le job de traitement
                    ImapProcessEventJob::dispatch($event->id);

                    $stats['processed']++;

                    if ($uid > $lastUid) {
                        $account->update(['sync_cursor' => (string) $uid]);
                        $lastUid = $uid;
                    }

                } catch (Throwable $e) {
                    $stats['errors']++;
                    Log::error('[IMAP] Erreur traitement message', [
                        'account_id' => $account->id,
                        'uid'        => $uid ?? null,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $client->disconnect();

            Log::info('[IMAP] Sync terminé', [
                'account_id' => $account->id,
                ...$stats,
            ]);

        } catch (Throwable $e) {
            Log::error('[IMAP] Sync échoué', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildClient(array $metadata): Client
    {
        $imap = $metadata['imap'];

        return (new ClientManager())->make([
            'host'          => $imap['host'],
            'port'          => (int) $imap['port'],
            'encryption'    => $imap['ssl'] ? 'ssl' : false,
            'validate_cert' => true,
            'username'      => $metadata['email'] ?? $metadata['email_root'] ?? '',
            'password'      => decrypt($imap['password']),
            'protocol'      => 'imap',
        ]);
    }

    /**
     * Ne garde que les pièces jointes image, filtre les logos de signature
     * (même liste que Gmail/Outlook/crawl : config('vision.filename_blacklist')),
     * et se limite à 1 image par email pour contrôler le coût.
     *
     * ⚠️ Écrit en base64 car $emailData finit dans SocialEvent.payload (colonne
     * JSON) — des octets bruts non-UTF8 corrompraient le json_encode().
     *
     * ⚠️ API Webklex : getAttachments()/getMimeType()/getName()/getContent() sont
     * celles de la branche stable actuelle (2.x/3.x). Si ta version installée
     * diverge (certaines anciennes versions exposent des propriétés au lieu de
     * méthodes), adapte ces 4 appels — le reste de la logique ne change pas.
     */
    private function extractImageAttachments($msg): array
    {
        $images = [];

        try {
            foreach ($msg->getAttachments() as $attachment) {

                $mime = strtolower($attachment->getMimeType() ?? '');

                if (!str_starts_with($mime, 'image/')) {
                    continue; // on ignore le mime AVANT de lire le contenu (évite un fetch IMAP inutile)
                }

                $filename = strtolower($attachment->getName() ?? 'image');

                $blacklisted = false;
                foreach (config('vision.filename_blacklist', []) as $needle) {
                    if (str_contains($filename, $needle)) {
                        $blacklisted = true;
                        break;
                    }
                }
                if ($blacklisted) {
                    continue;
                }

                $images[] = [
                    'mime'         => $mime,
                    'filename'     => $filename,
                    'bytes_base64' => base64_encode($attachment->getContent()),
                ];

                break; // 1 image suffit — cohérent avec le choix fait pour Gmail
            }
        } catch (Throwable $e) {
            Log::warning('[IMAP] Extraction pièce jointe échouée', ['error' => $e->getMessage()]);
        }

        return $images;
    }
}
