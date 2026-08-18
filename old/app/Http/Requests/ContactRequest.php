<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation Rules.
     */
    public function rules(): array
    {
        return [
            'fname' => [
                'required',
                'string',
                'min:2',
                'max:150'
            ],

            'phone' => [
                'nullable',
                'string',
                'min:6',
                'max:30'
            ],

            'email' => [
                'required',
                'email:rfc,dns',
                'max:255'
            ],

            'msg' => [
                'required',
                'string',
                'min:10',
                'max:5000'
            ],
        ];
    }

    /**
     * Messages personnalisés.
     */
    public function messages(): array
    {
        return [

            'fname.required' => 'Veuillez renseigner votre nom.',
            'fname.min'      => 'Le nom est trop court.',
            'fname.max'      => 'Le nom est trop long.',

            'phone.min'      => 'Le numéro de téléphone est invalide.',
            'phone.max'      => 'Le numéro de téléphone est invalide.',

            'email.required' => 'Veuillez renseigner votre adresse email.',
            'email.email'    => "L'adresse email est invalide.",

            'msg.required'   => 'Veuillez saisir votre message.',
            'msg.min'        => 'Votre message est trop court.',
            'msg.max'        => 'Votre message est trop long.',

        ];
    }

    /**
     * Noms lisibles.
     */
    public function attributes(): array
    {
        return [

            'fname' => 'nom',
            'phone' => 'téléphone',
            'email' => 'email',
            'msg'   => 'message',

        ];
    }
}
