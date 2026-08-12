<?php

namespace App\Http\Controllers\api\v4\Form;

use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreChatbotFormRequest;
use App\Http\Requests\Form\UpdateChatbotFormRequest;
use App\Http\Requests\SubmitChatFormRequest;
use App\Http\Resources\Form\ChatbotFormResource;
use App\Http\Resources\Form\ChatFormSubmissionResource;
use App\Mail\ChatFormSubmittedMail;
use App\Models\ChatbotCta;
use App\Models\ChatFormSubmission;
use App\Models\Document;
use App\Models\Form\ChatbotForm;
use App\Models\Form\ChatFormSubmissionFile;
use App\Models\Message;
use App\Models\ResourceEvent;
use App\Models\Site;
use App\Services\analytics\ResourceEventLogger;
use App\Services\Form\ChatbotFormFieldSyncService;
use App\Services\Form\DynamicFormValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;


class ChatbotFormController extends Controller
{
    public function __construct(
        private readonly ChatbotFormFieldSyncService $fieldSync,
        private readonly DynamicFormValidator $dynamicValidator,
        private readonly ResourceEventLogger $resourceEventLogger, // 🆕
    ) {
    }

    /**
     * GET /api/sites/{site}/forms
     * Liste paginée, même enveloppe que la pagination des CTAs.
     */
    public function index(Request $request, string $siteId): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 10);

        $paginator = ChatbotForm::query()
            ->where('site_id', $siteId)
            ->withCount('fields')
            ->with('fields')
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'data' => ChatbotFormResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    /**
     * GET /api/sites/{site}/forms/active
     * Liste légère des formulaires actifs, pour le select du bottom-sheet CTA.
     */
    public function active(string $siteId): JsonResponse
    {
        $forms = ChatbotForm::query()
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(ChatbotFormResource::collection($forms));
    }

    /**
     * GET /api/sites/{site}/forms/{form}
     */
    public function show(string $siteId, ChatbotForm $form): JsonResponse
    {
        $this->ensureBelongsToSite($form, $siteId);

        return response()->json(new ChatbotFormResource($form->load('fields')));
    }

    /**
     * GET /api/public/sites/{site}/forms/{form}
     *
     * Endpoint public (aucune authentification) utilisé par le widget
     * pour récupérer la définition d'un formulaire custom à afficher
     * suite à une CTA "open_form". Ne renvoie jamais un formulaire
     * désactivé, pour éviter qu'un visiteur soumette un formulaire
     * retiré côté admin.
     */
    public function public_show(string $siteId, ChatbotForm $form): JsonResponse
    {
        abort_unless($form->site_id === $siteId, 404);
        abort_unless($form->is_active, 404);

        return response()->json(new ChatbotFormResource($form->load('fields')));
    }

    /**
     * POST /api/sites/{site}/forms
     */
    public function store(StoreChatbotFormRequest $request, string $siteId): JsonResponse
    {
        $form = DB::transaction(function () use ($request, $siteId) {
            $form = ChatbotForm::create([
                'id' => (string) Str::uuid(),
                'site_id' => $siteId,
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'submit_label' => $request->input('submitLabel', 'Envoyer'),
                'success_message' => $request->input(
                    'successMessage',
                    'Merci, votre demande a bien été envoyée.'
                ),
                'is_active' => $request->boolean('isActive', true),
            ]);

            $this->fieldSync->sync($form, $request->input('fields', []));

            return $form;
        });

        return response()->json(new ChatbotFormResource($form->load('fields')), 201);
    }

    /**
     * PUT /api/sites/{site}/forms/{form}
     */
    public function update(UpdateChatbotFormRequest $request, string $siteId, ChatbotForm $form): JsonResponse
    {
        $this->ensureBelongsToSite($form, $siteId);

        DB::transaction(function () use ($request, $form) {
            $form->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'submit_label' => $request->input('submitLabel', $form->submit_label),
                'success_message' => $request->input('successMessage', $form->success_message),
                'is_active' => $request->boolean('isActive', $form->is_active),
            ]);

            $this->fieldSync->sync($form, $request->input('fields', []));
        });

        return response()->json(new ChatbotFormResource($form->fresh('fields')));
    }

    /**
     * POST /api/sites/{site}/forms/{form}/duplicate
     */
    public function duplicate(string $siteId, ChatbotForm $form): JsonResponse
    {
        $this->ensureBelongsToSite($form, $siteId);
        $form->load('fields');

        $duplicated = DB::transaction(function () use ($form) {
            $copy = ChatbotForm::create([
                'id' => (string) Str::uuid(),
                'site_id' => $form->site_id,
                'name' => $form->name . ' (copie)',
                'description' => $form->description,
                'submit_label' => $form->submit_label,
                'success_message' => $form->success_message,
                'is_active' => false, // une copie démarre désactivée par sécurité
            ]);

            foreach ($form->fields as $field) {
                $copy->fields()->create([
                    'id' => (string) Str::uuid(),
                    'field_key' => $field->field_key,
                    'label' => $field->label,
                    'field_type' => $field->field_type,
                    'placeholder' => $field->placeholder,
                    'help_text' => $field->help_text,
                    'is_required' => $field->is_required,
                    'position' => $field->position,
                    'options' => $field->options,
                    'validation' => $field->validation,
                    'conditional_logic' => $field->conditional_logic,
                ]);
            }

            return $copy;
        });

        return response()->json(new ChatbotFormResource($duplicated->load('fields')), 201);
    }

    /**
     * DELETE /api/sites/{site}/forms/{form}?force=true
     */
    public function destroy(Request $request, string $siteId, ChatbotForm $form): JsonResponse
    {
        $this->ensureBelongsToSite($form, $siteId);

        $usingCtas = $form->activeUsingCtas();

        if ($usingCtas->isNotEmpty() && ! $request->boolean('force')) {
            return response()->json([
                'message' => 'Ce formulaire est encore utilisé par une ou plusieurs CTAs actives.',
                'ctas' => $usingCtas->map(fn ($cta) => [
                    'id' => $cta->id,
                    'label' => $cta->label,
                ]),
            ], 409);
        }

        $form->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/sites/{site}/forms/{form}/submissions
     */
    public function submissions(Request $request, string $siteId, ChatbotForm $form): JsonResponse
    {
        $this->ensureBelongsToSite($form, $siteId);

        $perPage = (int) $request->integer('per_page', 10);

        $paginator = $form->submissions()
            ->with('files')
            ->latest('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => ChatFormSubmissionResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    private function ensureBelongsToSite(ChatbotForm $form, string $siteId): void
    {
        abort_unless($form->site_id === $siteId, 404);
    }

    /**
     * ⚠️ Remplace ta méthode `submitForm` existante par celle-ci (même
     * signature, donc aucune route à changer). Elle garde exactement le
     * comportement d'avant pour les formulaires système, et ajoute la
     * prise en charge des formulaires custom (validation dynamique,
     * logique conditionnelle, fichiers).
     */
    public function submitForm(SubmitChatFormRequest $request, string $siteId): JsonResponse
    {
        /**
         * @var Site $site
         */
        $site = Site::findOrFail($siteId);

        $validated = $request->validated();
        $formId = $validated['form_id'];

        Log::info('DANS SUBMITFORM', [
            'site_id' => $site->id,
            'message_id' => $validated['message_id'],
            'form_id' => $formId,
            'values' => $validated['values'],
        ]);

        try {
            // Un formulaire créé depuis l'admin est une ligne en base.
            // Un formulaire système (lead_form, quote_form, ...) ne l'est pas :
            // `form_id` est alors une simple clé en dur, jamais présente
            // dans `chatbot_forms`.
            $customForm = ChatbotForm::query()
                ->where('site_id', $site->id)
                ->where('id', $formId)
                ->with('fields')
                ->first();

            return $customForm
                ? $this->submitCustomForm($request, $site, $customForm)
                : $this->submitLegacyForm($site, $formId, $validated['message_id'], $validated['values']);

        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du formulaire.',
            ], 500);
        }
    }

    /**
     * Comportement historique, strictement inchangé.
     */
    private function submitLegacyForm(Site $site, string $formId, string $messageId, array $values): JsonResponse
    {
        $submission = ChatFormSubmission::create([
            'site_id' => $site->id,
            'message_id' => $messageId,
            'form_id' => $formId,
            'values' => $values,
        ]);

        $this->notifyAccount($site, $formId, $values);

        // fin de submitLegacyForm, avant le return
        $this->resourceEventLogger->logFormConversion($site, $formId, $messageId, $submission->id);
        //$this->logFormConversion($site, $formId, $messageId, $submission->id);

        return response()->json([
            'success' => true,
            'message' => 'Formulaire envoyé avec succès.',
            'submission_id' => $submission->id,
        ]);
    }

    /**
     * Formulaire custom : on revalide tout côté serveur selon sa
     * définition réelle (jamais confiance dans ce que le widget envoie),
     * puis on gère les éventuels fichiers, puis on notifie.
     */
    private function submitCustomForm(SubmitChatFormRequest $request, Site $site, ChatbotForm $customForm): JsonResponse
    {
        if (! $customForm->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Ce formulaire n\'est plus disponible.',
            ], 422);
        }

        // 1. Valide dynamiquement les valeurs (texte, nombre, select...) selon les champs du formulaire
        $cleanValues = $this->dynamicValidator->validate($customForm, $request->input('values', []));

        // 2. Gère les fichiers pour les champs de type "file" (upload multipart séparé)
        [$cleanValues, $uploadedFiles] = $this->handleFileUploads($request, $customForm, $cleanValues);

        $submission = DB::transaction(function () use ($request, $site, $customForm, $cleanValues, $uploadedFiles) {
            $submission = ChatFormSubmission::create([
                'site_id' => $site->id,
                'message_id' => $request->input('message_id'),
                'form_id' => $customForm->id,
                'values' => $cleanValues,
            ]);

            foreach ($uploadedFiles as $file) {
                ChatFormSubmissionFile::create(array_merge($file, [
                    'id' => (string) Str::uuid(),
                    'submission_id' => $submission->id,
                    'created_at' => now(),
                ]));
            }

            return $submission;
        });

        $this->notifyAccount($site, $customForm->name, $cleanValues, $customForm, $uploadedFiles);

        // fin de submitCustomForm, avant le return
        $this->resourceEventLogger->logFormConversion($site, $customForm->id, $request->input('message_id'), $submission->id);
        //$this->logFormConversion($site, $customForm->id, $request->input('message_id'), $submission->id);

        return response()->json([
            'success' => true,
            'message' => $customForm->success_message ?: 'Formulaire envoyé avec succès.',
            'submission_id' => $submission->id,
        ]);
    }

    /**
     * @return array{0: array, 1: array} [valeurs nettoyées (avec URL des fichiers), fichiers uploadés]
     */
    private function handleFileUploads(SubmitChatFormRequest $request, ChatbotForm $customForm, array $cleanValues): array
    {
        $uploaded = [];
        $fileFields = $customForm->fields->where('field_type', 'file');

        foreach ($fileFields as $field) {
            $file = $request->file("files.{$field->field_key}");

            if (! $file) {
                if ($field->is_required) {
                    throw ValidationException::withMessages([
                        "files.{$field->field_key}" => ['Le fichier "' . $field->label . '" est requis.'],
                    ]);
                }
                continue;
            }

            $validation = $field->validation ?? [];
            $maxKb = (int) (($validation['maxFileSizeMb'] ?? 5) * 1024);

            $request->validate([
                "files.{$field->field_key}" => array_filter([
                    'file',
                    "max:{$maxKb}",
                    ! empty($validation['acceptedFileTypes'])
                        ? 'mimes:' . str_replace(['.', ' '], '', $validation['acceptedFileTypes'])
                        : null,
                ]),
            ]);

            $document = $this->saveDocument($file, $customForm, 'file');

            $url = $document->path;

            $uploaded[] = [
                'field_key' => $field->field_key,
                'file_name' => $file->getClientOriginalName(),
                'file_url' => $url,
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ];

            // On garde une référence texte dans `values` (accès rapide sans jointure)
            $cleanValues[$field->field_key] = $url;
        }

        return [$cleanValues, $uploaded];
    }

    private function notifyAccount(Site $site, string $formLabel, array $values, ?ChatbotForm $form = null, array $files = []): void
    {
        $email = $site->account->email ?? null;

        if (! $email) {
            return;
        }

        Mail::to($email)->send(
            new ChatFormSubmittedMail($site, $formLabel, $values, $form, $files)
        );
    }

    private function moveImage($file)
    {
        $currentDateTime = Carbon::now();
        $formattedDateTime = $currentDateTime->format('Ymd_His');

        $path_file = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('assets/resources/forms'), $path_file);

        return "assets/resources/forms" . $path_file;
    }
    private function deleteImage($path)
    {
        if ( file_exists( public_path($path) ) ) {
            unlink(public_path($path));
        }
    }
    private function saveDocument($files, ChatbotForm $chatbotForm, string $type){

        $file_path = null;
        if (is_array($files)) {

            foreach ($files as $file) {
                $documentPath = $this->moveImage($file);
                $document = new Document(['id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type]);
                $file_path = $chatbotForm->documents()->save($document);
            }

        } else {

            $documentPath = $this->moveImage($files);
            $document = new Document(['id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type]);
            $file_path = $chatbotForm->documents()->save($document);

        }

        return $file_path;
    }

    private function logFormConversion(Site $site, string $formId, string $messageId, ChatFormSubmission $submission): void
    {
        $cta = ChatbotCta::where('site_id', $site->id)
            ->where('action', 'open_form')
            ->where('value', $formId)
            ->first();

        ResourceEvent::create([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'conversation_id' => Message::find($messageId)?->conversation_id,
            'message_id' => $messageId,
            'resource_type' => 'cta',
            'resource_id' => $cta?->id,
            'event_type' => 'conversion',
            'action' => 'open_form',
            'label' => $cta?->label,
            'metadata' => ['submission_id' => $submission->id, 'form_id' => $formId],
        ]);
    }
}
