<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotTask;
use App\Models\DevPilot\DevPilotTaskAttachment;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Private task attachment storage and bounded DOCX/text extraction. */
final class DevPilotTaskAttachmentService
{
    public const MAX_FILES = 8;
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
    public const MAX_EXTRACTED_BYTES = 120 * 1024;
    private const MAX_ZIP_ENTRIES = 1000;
    private const MAX_ZIP_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;
    private const MAX_DOCX_MEDIA = 24;

    public function storeForTask(DevPilotTask $task, array $uploads): array
    {
        $uploads = array_values(array_filter($uploads, static fn ($file): bool => $file instanceof UploadedFile));
        if (count($uploads) > self::MAX_FILES) throw new RuntimeException('Joignez au maximum huit fichiers par message.');
        $total = 0;
        foreach ($uploads as $file) {
            if (! $file->isValid()) throw new RuntimeException('Un fichier joint n’a pas été reçu correctement.');
            $total += (int) $file->getSize();
            if ($total > self::MAX_TOTAL_BYTES) throw new RuntimeException('La taille totale des fichiers joints dépasse 25 Mo.');
        }

        $batchId = (string) Str::uuid();
        try {
            foreach ($uploads as $upload) $this->storeOne($task, $upload, $batchId);
            return DevPilotTaskAttachment::query()->where('task_id', $task->id)
                ->where('storage_path', 'like', 'devpilot/tasks/'.$task->id.'/'.$batchId.'/%')->get()->all();
        } catch (Throwable $exception) {
            $prefix = 'devpilot/tasks/'.$task->id.'/'.$batchId.'/';
            $rows = DevPilotTaskAttachment::query()->where('task_id', $task->id)->where('storage_path', 'like', $prefix.'%')->get(['id', 'storage_path']);
            foreach ($rows as $row) Storage::disk('local')->delete((string) $row->storage_path);
            DevPilotTaskAttachment::query()->whereIn('id', $rows->pluck('id'))->delete();
            Storage::disk('local')->deleteDirectory('devpilot/tasks/'.$task->id.'/'.$batchId);
            throw $exception;
        }
    }

    public function deleteForTask(string $taskId): void
    {
        $this->deleteByIds(DevPilotTaskAttachment::query()->where('task_id', $taskId)->pluck('id')->all());
        Storage::disk('local')->deleteDirectory('devpilot/tasks/'.$taskId);
    }

    public function deleteByIds(array $ids): void
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        if ($ids === []) return;
        $rows = DevPilotTaskAttachment::query()->whereIn('id', $ids)->get(['id', 'storage_path']);
        foreach ($rows as $row) Storage::disk('local')->delete((string) $row->storage_path);
        DevPilotTaskAttachment::query()->whereIn('id', $rows->pluck('id'))->delete();
    }

    /** Model context is metadata plus a small text preview; full document text stays server-side. */
    public function modelContext(DevPilotTask $task): array
    {
        return $task->attachments()->get()->map(function (DevPilotTaskAttachment $attachment): array {
            $metadata = is_array($attachment->metadata) ? $attachment->metadata : [];
            $preview = $attachment->kind === 'document' ? app(DevPilotSecretRedactor::class)->redact(strip_tags((string) $attachment->extracted_content)) : '';
            return [
                'attachment_id' => (string) $attachment->id,
                'parent_attachment_id' => $attachment->parent_attachment_id,
                'kind' => (string) $attachment->kind,
                'name' => (string) $attachment->original_name,
                'mime_type' => (string) $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
                'sha256' => (string) $attachment->sha256,
                'preview' => $preview === '' ? null : mb_strcut($preview, 0, 500, 'UTF-8'),
                'alt' => is_string($metadata['alt'] ?? null) ? $metadata['alt'] : null,
                'embedded_media_ids' => array_values(array_filter($metadata['embedded_media_ids'] ?? [], 'is_string')),
                'plugin_slug' => is_string($metadata['plugin_slug'] ?? null) ? $metadata['plugin_slug'] : null,
                'secret_detected' => (bool) ($metadata['secret_detected'] ?? false),
            ];
        })->all();
    }

    public function getForTask(DevPilotTask $task, string $attachmentId, ?string $kind = null): DevPilotTaskAttachment
    {
        $query = DevPilotTaskAttachment::query()->where('task_id', $task->id)->whereKey($attachmentId);
        if ($kind !== null) $query->where('kind', $kind);
        $attachment = $query->first();
        if (! $attachment) throw new RuntimeException('La pièce jointe demandée ne fait pas partie de cette tâche.');
        return $attachment;
    }

    public function readBytes(DevPilotTaskAttachment $attachment): string
    {
        $bytes = Storage::disk('local')->get((string) $attachment->storage_path);
        if (! is_string($bytes) || strlen($bytes) !== (int) $attachment->size_bytes
            || ! hash_equals((string) $attachment->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Une pièce jointe ne correspond plus à son empreinte vérifiée.');
        }
        return $bytes;
    }

    public function previewImage(DevPilotTaskAttachment $attachment): ?string
    {
        if (! in_array($attachment->kind, ['image', 'embedded_image'], true) || ! function_exists('imagecreatefromstring')) return null;
        $bytes = $this->readBytes($attachment);
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ! isset($info[0], $info[1], $info['mime'])
            || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > 16000000
            || (string) $info['mime'] !== (string) $attachment->mime_type) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) return null;
        $width = imagesx($source); $height = imagesy($source);
        $scale = min(1, 320 / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        if (! $target) { imagedestroy($source); return null; }
        imagealphablending($target, false); imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        ob_start(); imagejpeg($target, null, 78); $bytes = (string) ob_get_clean();
        imagedestroy($source); imagedestroy($target);
        return $bytes === '' ? null : 'data:image/jpeg;base64,'.base64_encode($bytes);
    }

    private function storeOne(DevPilotTask $task, UploadedFile $upload, string $batchId): DevPilotTaskAttachment
    {
        $name = $this->safeFilename($upload->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = file_get_contents($upload->getRealPath());
        if (! is_string($bytes) || $bytes === '') throw new RuntimeException('Un fichier joint est vide ou illisible.');
        if (strlen($bytes) > self::MAX_FILE_BYTES) throw new RuntimeException('Chaque document joint doit faire au maximum 10 Mo.');
        $mime = $this->detectMime($bytes);
        $id = (string) Str::uuid();
        $parentId = null;
        $kind = 'image';
        $extracted = null;
        $metadata = [];

        if ($extension === 'zip') {
            if (! in_array($mime, ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream'], true)
                || ! class_exists(ZipArchive::class)) throw new RuntimeException('Le ZIP doit être une archive valide et ZipArchive doit être activé.');
            $zip = new ZipArchive();
            if ($zip->open($upload->getRealPath()) !== true || $zip->numFiles < 1 || $zip->numFiles > 1000) throw new RuntimeException('L’archive ZIP est invalide ou contient trop d’entrées.');
            $roots = []; $total = 0; $hasPhp = false; $seenEntries = [];
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i); $entry = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                    $normalized = rtrim($entry, '/'); $segments = explode('/', $normalized);
                    $unsafeSegment = false;
                    foreach ($segments as $segment) if ($segment === '' || $segment === '.' || $segment === '..') { $unsafeSegment = true; break; }
                    if ($normalized === '' || str_starts_with($entry, '/') || preg_match('/^[A-Za-z]:/', $entry) || preg_match('/[\x00-\x1f]/', $entry)
                        || $unsafeSegment) throw new RuntimeException('Le ZIP contient un chemin non autorisé.');
                    $entryKey = strtolower($normalized);
                    if (isset($seenEntries[$entryKey])) throw new RuntimeException('Le ZIP contient des chemins en double ou ambigus.');
                    $seenEntries[$entryKey] = true;
                    $root = $segments[0] ?? '';
                    if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $root) !== 1 || $root === 'elchat-devpilot') throw new RuntimeException('Le ZIP doit contenir un seul dossier d’extension avec un slug sûr; ELChat DevPilot est protégé.');
                    $entrySize = max(0, (int) ($stat['size'] ?? 0));
                    $roots[$root] = true; $total += $entrySize;
                    if ($entrySize > 10 * 1024 * 1024 || $total > 50 * 1024 * 1024) throw new RuntimeException('Le contenu décompressé du ZIP dépasse les limites de taille autorisées.');
                    if (str_ends_with(strtolower($entry), '.php')) $hasPhp = true;
                    $opsys = 0; $attrs = 0;
                    $external = $zip->getExternalAttributesIndex($i, $opsys, $attrs);
                    $entryType = ($attrs >> 16) & 0170000;
                    if ($external && $entryType !== 0 && ! in_array($entryType, [0100000, 0040000], true)) throw new RuntimeException('Le ZIP contient un lien ou un type de fichier spécial interdit.');
                }
            } finally { $zip->close(); }
            if (count($roots) !== 1 || ! $hasPhp) throw new RuntimeException('Le ZIP doit contenir une extension PHP dans un dossier racine unique.');
            $metadata = ['plugin_slug' => (string) array_key_first($roots), 'secret_detected' => false];
            $kind = 'plugin_zip';
        } elseif (in_array($extension, ['docx', 'txt', 'md'], true)) {
            $kind = 'document';
            if ($extension === 'docx') {
                if ($mime !== 'application/zip' && $mime !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                    throw new RuntimeException('Le type binaire du fichier DOCX ne correspond pas à son extension.');
                }
                [$extracted, $embedded] = $this->extractDocx($upload->getRealPath(), $task, $id, $batchId);
                $metadata['embedded_media_ids'] = array_column($embedded, 'id');
                $metadata['secret_detected'] = app(DevPilotSecretRedactor::class)->redact($extracted) !== $extracted;
                $path = $this->putBytes($task, $batchId, $id, $extension, $bytes);
                $document = $this->createRow($task, $id, null, $kind, $name, $extension, $mime, $bytes, $path, $extracted, $metadata);
                foreach ($embedded as $image) {
                    $imagePath = $this->putBytes($task, $batchId, $image['id'], $image['extension'], $image['bytes']);
                    $imageMetadata = ['alt' => $image['alt'], 'secret_detected' => false];
                    $this->createRow($task, $image['id'], $id, 'embedded_image', $image['name'], $image['extension'], $image['mime'], $image['bytes'], $imagePath, null, $imageMetadata);
                }
                return $document;
            }
            if (! in_array($mime, ['text/plain', 'text/markdown', 'application/octet-stream'], true)
                || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
                throw new RuntimeException('Le contenu de ce fichier TXT ou MD doit être un texte UTF-8 valide.');
            }
            $extracted = $extension === 'md' ? $this->markdownHtml($bytes) : $this->plainTextHtml($bytes);
            $metadata['secret_detected'] = app(DevPilotSecretRedactor::class)->redact($extracted) !== $extracted;
        } else {
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            if (! isset($mimeMap[$extension]) || $mimeMap[$extension] !== $mime) {
                throw new RuntimeException('Formats acceptés : DOCX, TXT, MD, ZIP de plugin, JPEG, PNG et WebP. Le format binaire doit correspondre à l’extension.');
            }
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) throw new RuntimeException('Chaque image jointe doit faire au maximum 5 Mo.');
            $kind = 'image';
            $metadata = ['alt' => '', 'secret_detected' => false];
        }

        $path = $this->putBytes($task, $batchId, $id, $extension, $bytes);
        return $this->createRow($task, $id, $parentId, $kind, $name, $extension, $mime, $bytes, $path, $extracted, $metadata);
    }

    private function extractDocx(string $path, DevPilotTask $task, string $documentId, string $batchId): array
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(DOMDocument::class)) throw new RuntimeException('L’extraction DOCX nécessite ZipArchive et DOM.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Le fichier DOCX n’est pas une archive ZIP valide.');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_ENTRIES) throw new RuntimeException('Le DOCX contient un nombre d’entrées ZIP non autorisé.');
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entryName = (string) ($stat['name'] ?? '');
                $total += (int) ($stat['size'] ?? 0);
                if ($entryName === '' || str_contains($entryName, '../') || str_starts_with($entryName, '/') || $total > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('Le DOCX contient un chemin ou un volume décompressé interdit.');
                }
            }
            $xml = $zip->getFromName('word/document.xml');
            if (! is_string($xml) || strlen($xml) > 5 * 1024 * 1024) throw new RuntimeException('Le DOCX ne contient pas un document Word lisible.');
            $document = $this->loadXml($xml);
            $relationships = $this->readRelationships($zip->getFromName('word/_rels/document.xml.rels') ?: '');
            $media = [];
            foreach ($relationships as $rid => $relation) {
                if (($relation['type'] ?? '') !== 'image') continue;
                $target = (string) ($relation['target'] ?? '');
                $entry = str_starts_with($target, 'word/') ? $target : 'word/'.ltrim($target, '/');
                $raw = $zip->getFromName($entry);
                if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_IMAGE_BYTES) continue;
                $mime = $this->detectMime($raw);
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
                if ($extension === null || count($media) >= self::MAX_DOCX_MEDIA) continue;
                $media[$rid] = ['id' => (string) Str::uuid(), 'name' => basename($entry), 'extension' => $extension,
                    'mime' => $mime, 'bytes' => $raw, 'alt' => ''];
            }
            $xpath = new \DOMXPath($document); $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $body = $xpath->query('/w:document/w:body')->item(0);
            if (! $body) throw new RuntimeException('Le DOCX ne contient pas de corps de document.');
            $html = $this->renderWordBlocks($body, $relationships, $media);
            if (strlen($html) > self::MAX_EXTRACTED_BYTES) throw new RuntimeException('Le contenu extrait dépasse 120 Ko et ne peut pas être envoyé à WordPress.');
            return [$html, array_values($media)];
        } finally {
            $zip->close();
        }
    }

    private function loadXml(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument(); $document->resolveExternals = false; $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) throw new RuntimeException('Un fichier DOCX contient un document XML invalide.');
            return $document;
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }

    private function readRelationships(string $xml): array
    {
        if ($xml === '' || strlen($xml) > 1024 * 1024) return [];
        $document = $this->loadXml($xml); $result = [];
        foreach ($document->getElementsByTagName('Relationship') as $node) {
            if (! $node instanceof DOMElement) continue;
            $id = $node->getAttribute('Id'); $target = $node->getAttribute('Target'); $type = $node->getAttribute('Type');
            if ($id === '' || $target === '' || str_contains($target, '..') || str_starts_with($target, 'http:') || str_starts_with($target, 'https:')) continue;
            $result[$id] = ['target' => $target, 'type' => str_ends_with($type, '/image') ? 'image' : (str_ends_with($type, '/hyperlink') ? 'hyperlink' : '')];
        }
        return $result;
    }

    private function renderWordBlocks(DOMNode $body, array $relationships, array &$media): string
    {
        $html = ''; $listType = null;
        foreach ($body->childNodes as $node) {
            $name = $node->localName;
            if ($name === 'p') {
                $list = $this->paragraphListType($node);
                if ($list !== $listType) {
                    if ($listType !== null) $html .= '</'.$listType.'>';
                    $listType = $list;
                    if ($listType !== null) $html .= '<'.$listType.'>';
                }
                $inline = $this->renderWordInline($node, $relationships, $media);
                $style = $this->paragraphStyle($node);
                if ($listType !== null) $html .= '<li>'.$inline.'</li>';
                elseif (preg_match('/^Heading([1-4])$/i', $style, $match)) $html .= '<h'.(int) $match[1].'>'.$inline.'</h'.(int) $match[1].'>';
                else $html .= '<p>'.$inline.'</p>';
            } elseif ($name === 'tbl') {
                if ($listType !== null) { $html .= '</'.$listType.'>'; $listType = null; }
                $html .= $this->renderWordTable($node, $relationships, $media);
            } else {
                $html .= $this->renderWordBlocks($node, $relationships, $media);
            }
        }
        if ($listType !== null) $html .= '</'.$listType.'>';
        return $html;
    }

    private function renderWordTable(DOMNode $table, array $relationships, array &$media): string
    {
        $html = '<table><tbody>';
        foreach ($table->childNodes as $row) {
            if ($row->localName !== 'tr') continue;
            $html .= '<tr>';
            foreach ($row->childNodes as $cell) {
                if ($cell->localName !== 'tc') continue;
                $html .= '<td>'.$this->renderWordBlocks($cell, $relationships, $media).'</td>';
            }
            $html .= '</tr>';
        }
        return $html.'</tbody></table>';
    }

    private function renderWordInline(DOMNode $node, array $relationships, array &$media): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $name = $child->localName;
            if (in_array($name, ['inline', 'anchor'], true)) {
                $docProperties = $child->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'docPr');
                if ($docProperties->length > 0) {
                    $docProperty = $docProperties->item(0);
                    $description = $docProperty instanceof DOMElement ? ($docProperty->getAttribute('descr') ?: $docProperty->getAttribute('title')) : '';
                    $blips = $child->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip');
                    if ($blips->length > 0) {
                        $rid = $blips->item(0)?->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')?->nodeValue;
                        if (is_string($rid) && isset($media[$rid]) && is_string($description)) $media[$rid]['alt'] = mb_strcut($description, 0, 512, 'UTF-8');
                    }
                }
            }
            if ($name === 't') { $html .= htmlspecialchars((string) $child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); continue; }
            if ($name === 'tab') { $html .= ' '; continue; }
            if ($name === 'br' || $name === 'cr') { $html .= '<br>'; continue; }
            if ($name === 'blip') {
                $rid = $child->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')?->nodeValue;
                if (is_string($rid) && $rid !== '') {
                    if (($relationships[$rid]['type'] ?? '') !== 'image' || ! isset($media[$rid])) {
                        throw new RuntimeException('Une image intégrée du DOCX est invalide, externe ou dans un format/une taille non pris en charge; aucune proposition incomplète ne sera créée.');
                    }
                    $html .= '{{media:'.$media[$rid]['id'].'}}';
                    continue;
                }
                $linkedRid = $child->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'link')?->nodeValue;
                if (is_string($linkedRid) && $linkedRid !== '') {
                    throw new RuntimeException('Le DOCX contient une image liée à une URL externe; seules les images réellement intégrées au document sont importées.');
                }
                throw new RuntimeException('Une image du DOCX ne possède pas de référence intégrée exploitable.');
            }
            $content = $this->renderWordInline($child, $relationships, $media);
            if ($name === 'hyperlink') {
                $rid = $child->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')?->nodeValue;
                $target = is_string($rid) ? (string) ($relationships[$rid]['target'] ?? '') : '';
                if (($relationships[$rid]['type'] ?? '') === 'hyperlink' && preg_match('#^(https?://|mailto:)#i', $target)) $content = '<a href="'.htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$content.'</a>';
            } elseif ($name === 'r') {
                $bold = false; $italic = false;
                foreach ($child->childNodes as $part) if ($part->localName === 'rPr') foreach ($part->childNodes as $mark) {
                    if ($mark->localName === 'b' && ! in_array($mark->getAttribute('val'), ['0', 'false'], true)) $bold = true;
                    if ($mark->localName === 'i' && ! in_array($mark->getAttribute('val'), ['0', 'false'], true)) $italic = true;
                }
                if ($bold) $content = '<strong>'.$content.'</strong>';
                if ($italic) $content = '<em>'.$content.'</em>';
            }
            $html .= $content;
        }
        return $html;
    }

    private function paragraphStyle(DOMNode $paragraph): string
    {
        foreach ($paragraph->childNodes as $properties) if ($properties->localName === 'pPr') {
            foreach ($properties->childNodes as $child) if ($child->localName === 'pStyle') return (string) $child->attributes?->getNamedItemNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')?->nodeValue;
        }
        return '';
    }

    private function paragraphListType(DOMNode $paragraph): ?string
    {
        foreach ($paragraph->childNodes as $properties) if ($properties->localName === 'pPr') {
            foreach ($properties->childNodes as $child) if ($child->localName === 'numPr') return 'ul';
        }
        return null;
    }

    private function plainTextHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n\s*\n/u', trim($text)) ?: [];
        $html = [];
        foreach ($blocks as $block) {
            $escaped = htmlspecialchars(trim($block), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($escaped !== '') $html[] = '<p>'.nl2br($escaped, false).'</p>';
        }
        $result = implode('', $html);
        if (strlen($result) > self::MAX_EXTRACTED_BYTES) throw new RuntimeException('Le contenu extrait dépasse 120 Ko et ne peut pas être envoyé à WordPress.');
        return $result;
    }

    private function markdownHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/u', trim($markdown)) ?: [];
        $html = []; $paragraph = []; $list = null;
        $closeList = static function () use (&$html, &$list): void {
            if ($list !== null) { $html[] = '</'.$list.'>'; $list = null; }
        };
        $inline = static function (string $line): string {
            $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escaped = preg_replace_callback('/\[([^\]]{1,512})\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/i', static fn (array $m): string => '<a href="'.htmlspecialchars(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$m[1].'</a>', $escaped) ?? $escaped;
            $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
            $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $escaped) ?? $escaped;
            return preg_replace('/`([^`\n]+)`/u', '<code>$1</code>', $escaped) ?? $escaped;
        };
        $flushParagraph = static function () use (&$html, &$paragraph, $inline, $closeList): void {
            if ($paragraph !== []) { $closeList(); $html[] = '<p>'.implode('<br>', array_map($inline, $paragraph)).'</p>'; $paragraph = []; }
        };
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') { $flushParagraph(); $closeList(); continue; }
            if (preg_match('/^(#{1,4})\s+(.+)$/u', $trimmed, $heading)) {
                $flushParagraph(); $closeList(); $level = strlen($heading[1]); $html[] = '<h'.$level.'>'.$inline($heading[2]).'</h'.$level.'>'; continue;
            }
            if (preg_match('/^[-*+]\s+(.+)$/u', $trimmed, $item)) {
                $flushParagraph(); if ($list !== 'ul') { $closeList(); $list = 'ul'; $html[] = '<ul>'; } $html[] = '<li>'.$inline($item[1]).'</li>'; continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $item)) {
                $flushParagraph(); if ($list !== 'ol') { $closeList(); $list = 'ol'; $html[] = '<ol>'; } $html[] = '<li>'.$inline($item[1]).'</li>'; continue;
            }
            $closeList(); $paragraph[] = $trimmed;
        }
        $flushParagraph(); $closeList();
        $result = implode('', $html);
        if (strlen($result) > self::MAX_EXTRACTED_BYTES) throw new RuntimeException('Le contenu extrait dépasse 120 Ko et ne peut pas être envoyé à WordPress.');
        return $result;
    }

    private function createRow(DevPilotTask $task, string $id, ?string $parentId, string $kind, string $name, string $extension, string $mime, string $bytes, string $path, ?string $content, array $metadata): DevPilotTaskAttachment
    {
        $attachment = new DevPilotTaskAttachment([
            'task_id' => $task->id, 'parent_attachment_id' => $parentId, 'kind' => $kind,
            'original_name' => $name, 'extension' => $extension, 'mime_type' => $mime, 'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'storage_path' => $path, 'extracted_content' => $content, 'metadata' => $metadata,
        ]);
        // `id` is intentionally not mass assignable; set it explicitly so embedded
        // image rows can reference the exact persisted document UUID as their parent.
        $attachment->setAttribute($attachment->getKeyName(), $id);
        $attachment->save();

        return $attachment;
    }

    private function putBytes(DevPilotTask $task, string $batchId, string $id, string $extension, string $bytes): string
    {
        $path = 'devpilot/tasks/'.$task->id.'/'.$batchId.'/'.$id.'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) throw new RuntimeException('Le stockage privé des pièces jointes est indisponible.');
        return $path;
    }

    private function detectMime(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return strtolower((string) $finfo->buffer(substr($bytes, 0, 262144)));
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'piece-jointe';
        return mb_strcut(trim($name, ' .'), 0, 240, 'UTF-8') ?: 'piece-jointe';
    }
}
