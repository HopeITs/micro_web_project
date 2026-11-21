<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The institution e-mail, or the desktop user name of an account that has no e-mail yet.
            'login' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['nullable', 'in:1,on,true'],
        ];
    }
}
