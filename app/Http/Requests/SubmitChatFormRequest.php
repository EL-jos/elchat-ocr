<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitChatFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     *
     *  Ces règles restent volontairement génériques : la validation fine
     *  par champ (types, required, logique conditionnelle, fichiers) est
     *  faite dynamiquement dans le contrôleur pour les formulaires custom,
     *  une fois qu'on sait de quel formulaire il s'agit.
     * 
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /*return [
            'form_id' => [ 'required', 'string' ],
            'values' => [ 'required', 'array' ],
            'message_id' => [ 'required', 'uuid' ],
        ];*/

        return [
            'form_id' => ['required', 'string'],
            // `present` (et pas `required`) : un formulaire composé
            // uniquement de champs fichiers peut légitimement envoyer un
            // tableau `values` vide.
            'values' => ['present', 'array'],
            'message_id' => ['required', 'uuid'],
        ];
    }
}
