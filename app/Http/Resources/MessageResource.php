<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * À utiliser dans ton contrôleur existant qui sert
 * GET /conversation/{id}/messages, à la place de la sérialisation actuelle :
 *
 *   return MessageResource::collection(
 *       $conversation->messages()
 *           ->with(['displayedCtas', 'chatFormSubmissions.files'])
 *           ->paginate($perPage)
 *   );
 *
 * ⚠️ Pas besoin d'ajouter 'attachment' au ->with() : Message::$with = ['attachment']
 * le charge déjà automatiquement sur toute requête.
 *
 * Sans ce ->with(['displayedCtas', 'chatFormSubmissions.files']), le panneau de
 * détail de l'onglet Conversations n'aura ni les CTAs affichées, ni les
 * soumissions de formulaire associées à chaque message.
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'role' => $this->role,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'entities' => $this->entities ?? [],

            // message_ctas (snapshot des CTAs réellement affichées sur ce message)
            'ctas' => $this->whenLoaded('displayedCtas', fn () => $this->displayedCtas->map(fn ($c) => [
                'id' => $c->cta_id,
                'label' => $c->label,
                'position' => $c->position,
                'action' => $c->action,
                'value' => $c->value,
                'style' => $c->style,
            ])),

            // Toujours chargée automatiquement (Message::$with = ['attachment']), pas de whenLoaded ici
            'attachment' => $this->attachment ? [
                'id' => $this->attachment->id,
                'type' => $this->attachment->type,
                'url' => $this->attachment->url,
                'description' => $this->attachment->description,
                'ocr_text' => $this->attachment->ocr_text,
            ] : null,

            // chatFormSubmissions() est hasMany côté modèle réel (un message peut,
            // en théorie, avoir plusieurs soumissions) — on renvoie donc un tableau.
            'chatFormSubmissions' => $this->whenLoaded('chatFormSubmissions', fn () => $this->chatFormSubmissions->map(fn ($s) => [
                'id' => $s->id,
                'formId' => $s->form_id,
                'values' => $s->values,
                'files' => $s->relationLoaded('files')
                    ? $s->files->map(fn ($f) => [
                        'fieldKey' => $f->field_key,
                        'fileName' => $f->file_name,
                        'fileUrl' => $f->file_url,
                        'mimeType' => $f->mime_type,
                        'sizeBytes' => $f->size_bytes,
                    ])
                    : [],
                'submittedAt' => optional($s->created_at)->toIso8601String(),
            ])),
        ];
    }
}
