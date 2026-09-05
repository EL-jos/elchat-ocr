<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;

final class PowerPointModule extends AbstractMicrosoft365Module
{
    private const POWERPOINT_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private const PDF_MIME = 'application/pdf';

    public function key(): string { return 'powerpoint'; }

    public function label(): string { return 'PowerPoint'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/d/df/Microsoft_Office_PowerPoint_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        $location = [
            'drive_id' => ['type' => 'string', 'description' => 'Optionnel : identifiant du drive SharePoint ciblé.'],
            'site_id' => ['type' => 'string', 'description' => 'Optionnel : identifiant du site SharePoint ciblé.'],
        ];
        $itemLocation = [
            'item_id' => ['type' => 'string', 'description' => 'Identifiant Graph de la présentation .pptx.'],
            ...$location,
        ];
        $slide = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titre visible sur la diapositive.'],
                'bullets' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'description' => 'Contenu visible sous le titre : informations, faits, étapes, décisions, résultats ou recommandations adaptés à la demande. Obligatoire.'],
            ],
            'required' => ['title', 'bullets'],
            'additionalProperties' => false,
        ];

        return [
            $this->readTool(
                'powerpoint_get_presentation',
                'Récupère les métadonnées d’une présentation PowerPoint .pptx présente dans OneDrive ou SharePoint.',
                $itemLocation,
                ['item_id'],
                'powerpoint.read',
            ),
            $this->readTool(
                'powerpoint_list_presentations',
                'Liste les présentations PowerPoint .pptx disponibles dans OneDrive ou SharePoint.',
                ['query' => ['type' => 'string', 'description' => 'Optionnel : texte à rechercher dans le nom.'], ...$location],
                [],
                'powerpoint.list_presentations',
            ),
            $this->writeTool(
                'powerpoint_create_presentation',
                'Crée un vrai fichier PowerPoint .pptx avec des diapositives structurées, des titres et le contenu correspondant à la demande de l’administrateur, puis le dépose dans OneDrive ou SharePoint. Ne jamais appeler cet outil avec des titres seuls : chaque diapositive doit contenir au moins une puce issue des informations disponibles.',
                ['name' => ['type' => 'string', 'description' => 'Nom du fichier, avec ou sans extension .pptx.'], 'title' => ['type' => 'string', 'description' => 'Titre de la diapositive unique, pratique pour une présentation simple.'], 'bullets' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'description' => 'Contenu de la diapositive unique. Obligatoire si slides n’est pas utilisé.'], 'slides' => ['type' => 'array', 'items' => $slide, 'minItems' => 1, 'description' => 'Diapositives à générer dans l’ordre ; chaque objet doit contenir title et bullets non vide, adaptés au sujet demandé.'], 'parent_item_id' => ['type' => 'string'], ...$location],
                ['name'],
                'powerpoint.create_presentation',
                'confirm',
            ),
            $this->writeTool(
                'powerpoint_add_slide',
                'Ajoute une nouvelle diapositive réellement structurée à une présentation PowerPoint .pptx existante. Le contenu en puces est obligatoire ; un titre seul ne doit jamais être ajouté.',
                [...$itemLocation, 'title' => ['type' => 'string', 'description' => 'Titre de la nouvelle diapositive.'], 'bullets' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'description' => 'Contenu de la nouvelle diapositive. Obligatoire.']],
                ['item_id', 'title', 'bullets'],
                'powerpoint.add_slide',
                'confirm',
            ),
            $this->writeTool(
                'powerpoint_upload_presentation',
                'Dépose ou remplace une présentation PowerPoint .pptx existante dans OneDrive ou SharePoint.',
                ['name' => ['type' => 'string'], 'content' => ['type' => 'string'], 'content_base64' => ['type' => 'string'], 'parent_item_id' => ['type' => 'string'], ...$location],
                ['name'],
                'powerpoint.upload',
                'confirm',
            ),
            $this->writeTool(
                'powerpoint_export_to_pdf',
                'Convertit une présentation PowerPoint en vrai fichier PDF via Microsoft Graph et le dépose dans le même emplacement.',
                [...$itemLocation, 'name' => ['type' => 'string', 'description' => 'Optionnel : nom du fichier PDF de sortie.']],
                ['item_id'],
                'powerpoint.export_to_pdf',
                'confirm',
            ),
            $this->writeTool(
                'powerpoint_delete_presentation',
                'Supprime une présentation PowerPoint .pptx de OneDrive ou SharePoint.',
                $itemLocation,
                ['item_id'],
                'powerpoint.delete_presentation',
                'confirm',
            ),
            $this->writeTool(
                'powerpoint_rename_presentation',
                'Renomme une présentation PowerPoint .pptx existante sans changer son contenu.',
                [...$itemLocation, 'name' => ['type' => 'string', 'description' => 'Nouveau nom, avec ou sans extension .pptx.']],
                ['item_id', 'name'],
                'powerpoint.rename_presentation',
                'confirm',
            ),
            $this->writeTool(
                'powerpoint_share_presentation',
                'Invite une adresse e-mail à accéder à une présentation PowerPoint .pptx.',
                [...$itemLocation, 'email' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => ['read', 'write']]],
                ['item_id', 'email'],
                'powerpoint.share_presentation',
                'confirm',
            ),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'powerpoint_get_presentation' => ['Files.Read'],
            'powerpoint_list_presentations' => ['Files.Read'],
            'powerpoint_create_presentation' => ['Files.ReadWrite'],
            'powerpoint_add_slide' => ['Files.ReadWrite'],
            'powerpoint_upload_presentation' => ['Files.ReadWrite'],
            'powerpoint_export_to_pdf' => ['Files.ReadWrite'],
            'powerpoint_delete_presentation' => ['Files.ReadWrite'],
            'powerpoint_rename_presentation' => ['Files.ReadWrite'],
            'powerpoint_share_presentation' => ['Files.ReadWrite'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'powerpoint_get_presentation' => $this->getPresentation($graph, $params),
            'powerpoint_list_presentations' => $this->listPresentations($graph, $params),
            'powerpoint_create_presentation' => $this->createPresentation($graph, $params),
            'powerpoint_add_slide' => $this->addSlide($graph, $params),
            'powerpoint_upload_presentation' => $this->uploadPresentation($graph, $params),
            'powerpoint_export_to_pdf' => $this->exportToPdf($graph, $params),
            'powerpoint_delete_presentation' => $this->deletePresentation($graph, $params),
            'powerpoint_rename_presentation' => $this->renamePresentation($graph, $params),
            'powerpoint_share_presentation' => $this->sharePresentation($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module PowerPoint Microsoft 365."),
        };
    }

    private function getPresentation(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $file = $this->presentationMetadata($g, $p);
        if ($file === null) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas une présentation PowerPoint .pptx.');
        }

        return ToolResult::ok(['presentation' => $this->fileSummary($file)], 'Présentation PowerPoint récupérée.');
    }

    private function listPresentations(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $query = $this->odata((string) ($p['query'] ?? 'pptx'));
        $items = $g->collectPages($this->drivePrefix($p) . "/root/search(q='" . rawurlencode($query) . "')", [
            '$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime,createdDateTime,parentReference,eTag',
        ]);
        $presentations = array_values(array_filter($items, static fn (array $item): bool => str_ends_with(strtolower((string) ($item['name'] ?? '')), '.pptx')));

        return ToolResult::ok(
            ['presentations' => array_map([$this, 'fileSummary'], $presentations)],
            count($presentations) . ' présentation(s) PowerPoint trouvée(s)',
        );
    }

    private function createPresentation(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $nameInput = trim((string) ($p['name'] ?? ''));
        if ($nameInput === '') {
            return ToolResult::fail('invalid_input', 'Le nom du fichier PowerPoint est obligatoire.');
        }

        $rawSlides = $p['slides'] ?? [[
            'title' => (string) ($p['title'] ?? pathinfo($nameInput, PATHINFO_FILENAME)),
            'bullets' => $p['bullets'] ?? [],
        ]];
        $slides = $this->normaliseSlides($rawSlides);
        if ($slides instanceof ToolResult) {
            return $slides;
        }

        $name = $this->safeFilename($nameInput, '.pptx');
        $temporaryPath = tempnam(sys_get_temp_dir(), 'elchat-ppt-create-');
        if ($temporaryPath === false) {
            return ToolResult::fail('presentation_generation_failed', 'Le fichier PowerPoint temporaire n’a pas pu être préparé.');
        }

        $content = null;
        try {
            $presentation = new PhpPresentation();
            $presentation->getDocumentProperties()->setTitle($slides[0]['title']);
            $presentation->getDocumentProperties()->setSubject('Présentation générée par ELChat');
            $this->populateSlide($presentation->getActiveSlide(), $slides[0]);
            foreach (array_slice($slides, 1) as $slide) {
                $this->populateSlide($presentation->createSlide(), $slide);
            }

            IOFactory::createWriter($presentation, 'PowerPoint2007')->save($temporaryPath);
            $content = file_get_contents($temporaryPath);
        } catch (\Throwable $exception) {
            return ToolResult::fail('presentation_generation_failed', 'La présentation PowerPoint n’a pas pu être générée.');
        } finally {
            @unlink($temporaryPath);
        }

        if ($content === false || !is_string($content) || !str_starts_with($content, 'PK')) {
            return ToolResult::fail('presentation_generation_failed', 'La présentation PowerPoint générée est invalide.');
        }

        try {
            $file = $this->uploadBytes($g, $p, $name, $content, self::POWERPOINT_MIME);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ToolResult::fail('upload_failed', $exception->getMessage());
        }

        return ToolResult::ok([
            'presentation' => $this->fileSummary($file),
            'slide_count' => count($slides),
        ], 'Présentation PowerPoint créée avec son contenu structuré.');
    }

    private function addSlide(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '') {
            return ToolResult::fail('invalid_input', 'Le titre de la nouvelle diapositive est obligatoire.');
        }

        $slides = $this->normaliseSlides([['title' => $title, 'bullets' => $p['bullets'] ?? []]]);
        if ($slides instanceof ToolResult) {
            return $slides;
        }

        $file = $this->presentationMetadata($g, $p);
        if ($file === null) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas une présentation PowerPoint .pptx.');
        }

        $sourcePath = tempnam(sys_get_temp_dir(), 'elchat-ppt-source-');
        $outputPath = tempnam(sys_get_temp_dir(), 'elchat-ppt-output-');
        if ($sourcePath === false || $outputPath === false) {
            if ($sourcePath !== false) @unlink($sourcePath);
            if ($outputPath !== false) @unlink($outputPath);
            return ToolResult::fail('presentation_edit_failed', 'Les fichiers temporaires PowerPoint n’ont pas pu être préparés.');
        }

        $updatedContent = null;
        $slideIndex = 0;
        try {
            $sourceContent = $g->download($this->itemPath($p) . '/content');
            if (!str_starts_with($sourceContent, 'PK') || file_put_contents($sourcePath, $sourceContent) === false) {
                return ToolResult::fail('invalid_file_type', 'Le contenu téléchargé n’est pas une présentation .pptx valide.');
            }

            $presentation = IOFactory::load($sourcePath);
            $newSlide = $presentation->createSlide();
            $this->populateSlide($newSlide, $slides[0]);
            $slideIndex = $presentation->getSlideCount() - 1;
            IOFactory::createWriter($presentation, 'PowerPoint2007')->save($outputPath);
            $updatedContent = file_get_contents($outputPath);
        } catch (\Throwable $exception) {
            return ToolResult::fail('presentation_edit_failed', 'La diapositive n’a pas pu être ajoutée à la présentation PowerPoint.');
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
        }

        if ($updatedContent === false || !is_string($updatedContent) || !str_starts_with($updatedContent, 'PK')) {
            return ToolResult::fail('presentation_edit_failed', 'La présentation PowerPoint modifiée est invalide.');
        }

        $updatedFile = $g->putContent($this->itemPath($p) . '/content', $updatedContent, self::POWERPOINT_MIME);
        return ToolResult::ok([
            'presentation' => $this->fileSummary($updatedFile ?: $file),
            'slide_index' => $slideIndex,
            'slide_count' => $slideIndex + 1,
        ], 'Diapositive ajoutée à la présentation PowerPoint.');
    }

    private function uploadPresentation(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $content = $this->binaryContent($p);
        if ($content === null) {
            return ToolResult::fail('invalid_input', 'Le contenu PowerPoint est absent ou son encodage base64 est invalide.');
        }

        $name = $this->safeFilename((string) $p['name'], '.pptx');
        if (!str_ends_with(strtolower($name), '.pptx') || !str_starts_with($content, 'PK')) {
            return ToolResult::fail('invalid_file_type', 'PowerPoint attend une présentation Office Open XML .pptx valide.');
        }

        try {
            $file = $this->uploadBytes($g, $p, $name, $content, self::POWERPOINT_MIME);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ToolResult::fail('upload_failed', $exception->getMessage());
        }

        return ToolResult::ok(['presentation' => $this->fileSummary($file)], 'Présentation PowerPoint déposée dans Microsoft 365.');
    }

    private function exportToPdf(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $file = $this->presentationMetadata($g, $p);
        if ($file === null) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas une présentation PowerPoint .pptx.');
        }

        $pdf = $g->download($this->itemPath($p) . '/content', ['format' => 'pdf']);
        if (!str_starts_with($pdf, '%PDF-')) {
            return ToolResult::fail('export_failed', 'Microsoft Graph n’a pas retourné un document PDF valide.');
        }

        $sourceName = (string) ($file['name'] ?? 'presentation.pptx');
        $defaultName = preg_replace('/\.pptx$/i', '.pdf', $sourceName) ?: 'presentation.pdf';
        $pdfName = $this->safeFilename((string) ($p['name'] ?? $defaultName), '.pdf');
        $uploadParams = $p;
        if (empty($uploadParams['parent_item_id']) && !empty($file['parentReference']['id'])) {
            $uploadParams['parent_item_id'] = $file['parentReference']['id'];
        }

        try {
            $pdfFile = $this->uploadBytes($g, $uploadParams, $pdfName, $pdf, self::PDF_MIME);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ToolResult::fail('export_failed', $exception->getMessage());
        }

        return ToolResult::ok(['pdf' => $this->fileSummary($pdfFile)], 'Présentation PowerPoint exportée en PDF.');
    }

    private function deletePresentation(MicrosoftGraphClient $g, array $p): ToolResult
    {
        if ($this->presentationMetadata($g, $p) === null) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas une présentation PowerPoint .pptx.');
        }

        $g->delete($this->itemPath($p));
        return ToolResult::ok(['item_id' => $p['item_id']], 'Présentation PowerPoint supprimée.');
    }

    private function renamePresentation(MicrosoftGraphClient $g, array $p): ToolResult
    {
        if ($this->presentationMetadata($g, $p) === null) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas une présentation PowerPoint .pptx.');
        }

        $nameInput = trim((string) ($p['name'] ?? ''));
        if ($nameInput === '') {
            return ToolResult::fail('invalid_input', 'Le nouveau nom de la présentation est obligatoire.');
        }

        $name = $this->safeFilename($nameInput, '.pptx');
        $file = $g->patch($this->itemPath($p), ['name' => $name]);
        return ToolResult::ok(['presentation' => $this->fileSummary($file)], 'Présentation PowerPoint renommée.');
    }

    private function sharePresentation(MicrosoftGraphClient $g, array $p): ToolResult
    {
        if ($this->presentationMetadata($g, $p) === null) {
            return ToolResult::fail('invalid_file_type', 'L’élément demandé n’est pas une présentation PowerPoint .pptx.');
        }

        $email = trim((string) ($p['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ToolResult::fail('invalid_input', 'L’adresse e-mail de partage est invalide.');
        }

        $role = in_array($p['role'] ?? 'read', ['read', 'write'], true) ? $p['role'] : 'read';
        $g->post($this->itemPath($p) . '/invite', [
            'recipients' => [['email' => $email]],
            'requireSignIn' => true,
            'sendInvitation' => true,
            'roles' => [$role],
        ]);

        return ToolResult::ok(['item_id' => $p['item_id'], 'recipient' => $email, 'role' => $role], 'Invitation de partage PowerPoint envoyée.');
    }

    /** @return array<string, mixed>|null */
    private function presentationMetadata(MicrosoftGraphClient $g, array $p): ?array
    {
        $file = $g->get($this->itemPath($p), ['$select' => 'id,name,size,file,webUrl,lastModifiedDateTime,createdDateTime,parentReference,eTag']);
        return str_ends_with(strtolower((string) ($file['name'] ?? '')), '.pptx') ? $file : null;
    }

    /** @return array<int, array{title:string,bullets:array<int,string>}|ToolResult> */
    private function normaliseSlides(mixed $rawSlides): array|ToolResult
    {
        if (!is_array($rawSlides) || $rawSlides === [] || count($rawSlides) > 100) {
            return ToolResult::fail('invalid_input', 'La présentation doit contenir entre 1 et 100 diapositives.');
        }

        $slides = [];
        foreach ($rawSlides as $rawSlide) {
            if (!is_array($rawSlide)) {
                return ToolResult::fail('invalid_input', 'Chaque diapositive doit contenir un titre et une liste de puces.');
            }

            $title = trim((string) ($rawSlide['title'] ?? ''));
            $bullets = $rawSlide['bullets'] ?? [];
            if ($title === '' || mb_strlen($title) > 500 || !is_array($bullets) || count($bullets) > 30) {
                return ToolResult::fail('invalid_input', 'Chaque diapositive doit avoir un titre et au maximum 30 puces.');
            }

            $normalisedBullets = [];
            foreach ($bullets as $bullet) {
                $value = trim((string) $bullet);
                if ($value !== '') {
                    if (mb_strlen($value) > 1000) {
                        return ToolResult::fail('invalid_input', 'Une puce PowerPoint ne peut pas dépasser 1 000 caractères.');
                    }
                    $normalisedBullets[] = $value;
                }
            }

            if ($normalisedBullets === []) {
                return ToolResult::fail('invalid_input', 'Le contenu est obligatoire : chaque diapositive doit contenir au moins une puce adaptée à la demande. Réessayez en transmettant les informations, faits, étapes, décisions, résultats ou recommandations disponibles ; un titre seul est refusé.');
            }

            $slides[] = ['title' => $title, 'bullets' => $normalisedBullets];
        }

        return $slides;
    }

    /** @param array{title:string,bullets:array<int,string>} $slideData */
    private function populateSlide(object $slide, array $slideData): void
    {
        $slide->setName($slideData['title']);

        $title = $slide->createRichTextShape()
            ->setHeight(80)
            ->setWidth(880)
            ->setOffsetX(40)
            ->setOffsetY(30)
            ->setAutoFit(RichText::AUTOFIT_NORMAL);
        $title->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $title->getActiveParagraph()->getFont()->setSize(28)->setBold(true);
        $title->getActiveParagraph()->createTextRun($slideData['title']);

        if ($slideData['bullets'] === []) {
            return;
        }

        $body = $slide->createRichTextShape()
            ->setHeight(460)
            ->setWidth(820)
            ->setOffsetX(70)
            ->setOffsetY(140)
            ->setAutoFit(RichText::AUTOFIT_NORMAL);

        foreach ($slideData['bullets'] as $index => $bullet) {
            $paragraph = $index === 0 ? $body->getActiveParagraph() : $body->createParagraph();
            $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET)->setBulletChar('•');
            $paragraph->getFont()->setSize(20);
            $paragraph->createTextRun($bullet);
        }
    }

}
