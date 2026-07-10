<?php

namespace App\Http\Resources\Form;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatFormSubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'siteId' => $this->site_id,
            'messageId' => $this->message_id,
            'formId' => $this->form_id,
            'values' => $this->values,
            'files' => $this->whenLoaded('files', function () {
                return $this->files->map(fn ($file) => [
                    'fieldKey' => $file->field_key,
                    'fileName' => $file->file_name,
                    'fileUrl' => $file->file_url,
                    'mimeType' => $file->mime_type,
                    'sizeBytes' => $file->size_bytes,
                ]);
            }),
            'createdAt' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
