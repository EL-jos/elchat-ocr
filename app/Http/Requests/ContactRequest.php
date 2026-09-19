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

            'fname.required' => __('site.contact.validation.name_required'),
            'fname.min'      => __('site.contact.validation.name_min'),
            'fname.max'      => __('site.contact.validation.name_min'),

            'phone.min'      => __('site.contact.validation.phone_min'),
            'phone.max'      => __('site.contact.validation.phone_min'),

            'email.required' => __('site.contact.validation.email_required'),
            'email.email'    => __('site.contact.validation.email_invalid'),

            'msg.required'   => __('site.contact.validation.message_required'),
            'msg.min'        => __('site.contact.validation.message_min'),
            'msg.max'        => __('site.contact.validation.message_min'),

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
