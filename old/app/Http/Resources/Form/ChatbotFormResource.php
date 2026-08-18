<?php

namespace App\Http\Resources\Form;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatbotFormResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'submitLabel' => $this->submit_label,
            'successMessage' => $this->success_message,
            'isActive' => (bool) $this->is_active,
            'fields' => ChatbotFormFieldResource::collection($this->whenLoaded('fields')),
            'createdAt' => optional($this->created_at)->toIso8601String(),
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
