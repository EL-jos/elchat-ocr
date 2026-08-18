<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'siteId' => $this->site_id,
            'status' => $this->status,
            'summary' => $this->summary,
            'summaryUpdatedAt' => optional($this->summary_updated_at)->toIso8601String(),
            'metadata' => $this->metadata ?: (object) [],

            'memory' => $this->whenLoaded('memory', function () {
                return $this->memory ? [
                    'preferences' => $this->memory->preferences(),
                    'objectives' => $this->memory->objectives(),
                    'constraints' => $this->memory->constraints(),
                    'decisions' => $this->memory->decisions(),
                    'userInfo' => $this->memory->userInfo(),
                ] : null;
            }),

            'visitor' => $this->whenLoaded('visitor', function () {
                return $this->visitor ? [
                    'id' => $this->visitor->id,
                    'ip' => $this->visitor->ip,
                    'device' => $this->visitor->device,
                    'userAgent' => $this->visitor->user_agent,
                ] : null;
            }),

            'user' => $this->whenLoaded('user', function () {
                return $this->user ? [
                    'id' => $this->user->id,
                    'firstname' => $this->user->firstname,
                    'lastname' => $this->user->lastname,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ] : null;
            }),

            'latestFormSubmission' => $this->whenLoaded('formSubmissions', function () {
                $submission = $this->formSubmissions->first();

                if (! $submission) {
                    return null;
                }

                return [
                    'id' => $submission->id,
                    'formId' => $submission->form_id,
                    'values' => $submission->values,
                    'files' => $submission->relationLoaded('files')
                        ? $submission->files->map(fn ($f) => [
                            'fieldKey' => $f->field_key,
                            'fileName' => $f->file_name,
                            'fileUrl' => $f->file_url,
                            'mimeType' => $f->mime_type,
                            'sizeBytes' => $f->size_bytes,
                        ])
                        : [],
                    'submittedAt' => optional($submission->created_at)->toIso8601String(),
                ];
            }),

            'createdAt' => optional($this->created_at)->toIso8601String(),
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
