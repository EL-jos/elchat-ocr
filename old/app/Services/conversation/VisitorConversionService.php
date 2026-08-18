<?php

namespace App\Services\conversation;

use App\Models\ChatFormSubmission;
use App\Models\Conversation;
use App\Models\Form\ChatbotForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VisitorConversionService
{
    /**
     * Convertit le visiteur anonyme d'une conversation en utilisateur enregistré,
     * à partir des informations de contact trouvées dans les formulaires qu'il a
     * soumis pendant la conversation.
     *
     * @return array{success: bool, message: string, user?: array}
     */
    public function convert(Conversation $conversation): array
    {
        if ($conversation->user_id) {
            return [
                'success' => false,
                'message' => 'Cette conversation est déjà rattachée à un utilisateur.',
            ];
        }

        if (! $conversation->visitor_id) {
            return [
                'success' => false,
                'message' => 'Aucun visiteur anonyme associé à cette conversation.',
            ];
        }

        $contact = $this->extractContactInfo($conversation);

        if (empty($contact['email'])) {
            return [
                'success' => false,
                'message' => 'Aucune adresse email exploitable trouvée dans les formulaires soumis pendant cette conversation.',
            ];
        }

        $user = DB::transaction(function () use ($conversation, $contact) {
            $user = User::where('email', $contact['email'])->first();

            if (! $user) {
                [$firstname, $lastname] = $this->splitName($contact['fullName']);

                $user = User::create([
                    'id' => (string) Str::uuid(),
                    'firstname' => $firstname ?: 'Visiteur',
                    'lastname' => $lastname ?: '',
                    'email' => $contact['email'],
                    'phone' => $contact['phone'],
                    // Aucun mot de passe défini par l'utilisateur : hash aléatoire pour
                    // ne pas laisser le champ vide. À faire suivre d'un email "définir
                    // votre mot de passe" si le produit veut activer ce compte plus tard.
                    'password' => Hash::make(Str::random(40)),
                    'is_verified' => false,
                ]);
            }

            $conversation->update(['user_id' => $user->id]);

            if ($conversation->visitor) {
                $conversation->visitor->update(['user_id' => $user->id]);
            }

            return $user;
        });

        return [
            'success' => true,
            'message' => 'Visiteur converti en utilisateur avec succès.',
            'user' => [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ];
    }

    /**
     * Cherche, dans les soumissions de formulaire de la conversation, des
     * informations de contact exploitables. Priorité aux champs typés
     * explicitement (formulaires custom, via chatbot_form_fields.field_type),
     * avec repli sur les clés conventionnelles utilisées par les formulaires
     * système (email / phone / name).
     */
    private function extractContactInfo(Conversation $conversation): array
    {
        $messageIds = $conversation->messages()->pluck('id');

        $submissions = ChatFormSubmission::query()
            ->whereIn('message_id', $messageIds)
            ->latest()
            ->get();

        $email = null;
        $phone = null;
        $fullName = null;

        foreach ($submissions as $submission) {
            $values = $submission->values ?? [];
            $customForm = ChatbotForm::with('fields')->find($submission->form_id);

            if ($customForm) {
                foreach ($customForm->fields as $field) {
                    $value = $values[$field->field_key] ?? null;

                    if (empty($value)) {
                        continue;
                    }

                    if (! $email && $field->field_type === 'email') {
                        $email = $value;
                    }

                    if (! $phone && $field->field_type === 'phone') {
                        $phone = $value;
                    }

                    if (! $fullName && $field->field_type === 'text'
                        && Str::contains(Str::lower($field->label), ['nom', 'name'])) {
                        $fullName = $value;
                    }
                }
            }

            // Repli sur les clés conventionnelles (formulaires système : lead_form,
            // quote_form, booking_form, support_form utilisent tous 'name'/'email'/'phone')
            $email ??= $values['email'] ?? null;
            $phone ??= $values['phone'] ?? null;
            $fullName ??= $values['name'] ?? $values['full_name'] ?? null;

            if ($email) {
                break; // une adresse email suffit à identifier le contact
            }
        }

        return compact('email', 'phone', 'fullName');
    }

    private function splitName(?string $fullName): array
    {
        if (! $fullName) {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [$parts[0] ?? null, $parts[1] ?? null];
    }
}
