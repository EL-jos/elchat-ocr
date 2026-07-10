<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstname' => 'required_without_all:google_token,facebook_token|string|max:255',
            'lastname'  => 'required_without_all:google_token,facebook_token|string|max:255',
            'email'     => 'required_without_all:google_token,facebook_token|email|unique:users,email',
            'phone'     => 'nullable|unique:users,phone',
            'password'  => 'required_without_all:google_token,facebook_token|string|min:6|confirmed',
            'is_admin'  => 'required|boolean',
            'account_name' => 'required_if:is_admin,true|string|max:255',
            'site_id' => 'required_if:is_admin,false|uuid|exists:sites,id',
            'google_token' => 'nullable|string',
            'facebook_token' => 'nullable|string', 
        ];
    }
}
