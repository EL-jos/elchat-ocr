<?php

namespace App\Services\Form;

use App\Models\Form\ChatbotForm;
use App\Models\Form\ChatbotFormField;
use Illuminate\Support\Str;

class ChatbotFormFieldSyncService
{
    /**
     * Remplace intégralement la liste des champs d'un formulaire à partir
     * du payload front (camelCase). Fait un upsert par id de champ quand
     * fourni, crée sinon, et supprime les champs absents du payload.
     */
    public function sync(ChatbotForm $form, array $fieldsPayload): void
    {
        $keptIds = [];

        foreach ($fieldsPayload as $index => $data) {
            $fieldKey = $data['fieldKey'] ?? null;

            if (empty($fieldKey)) {
                $fieldKey = $this->slugify($data['label']);
            }

            $attributes = [
                'field_key' => $fieldKey,
                'label' => $data['label'],
                'field_type' => $data['fieldType'],
                'placeholder' => $data['placeholder'] ?? null,
                'help_text' => $data['helpText'] ?? null,
                'is_required' => $data['isRequired'] ?? false,
                'position' => $data['position'] ?? $index,
                'options' => $data['options'] ?? [],
                'validation' => $data['validation'] ?? null,
                'conditional_logic' => (($data['conditionalLogic']['enabled'] ?? false))
                    ? $data['conditionalLogic']
                    : null,
            ];

            $existingId = $data['id'] ?? null;

            if ($existingId && $form->fields()->whereKey($existingId)->exists()) {
                $field = $form->fields()->whereKey($existingId)->first();
                $field->update($attributes);
            } else {
                $field = $form->fields()->create(array_merge($attributes, [
                    'id' => (string) Str::uuid(),
                ]));
            }

            $keptIds[] = $field->id;
        }

        // Supprime les champs qui ne sont plus présents dans le payload
        ChatbotFormField::where('form_id', $form->id)
            ->whereNotIn('id', $keptIds)
            ->delete();
    }

    private function slugify(string $text): string
    {
        $slug = Str::of($text)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');

        return $slug->isEmpty() ? 'champ_' . Str::random(6) : (string) $slug;
    }
}
