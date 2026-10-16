<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

class RegisterRequest extends FormRequest
{
    use Concerns\NormalizesAccountDetails;
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['first_name' => ['required', 'string', 'max:255'], 'last_name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^04[0-9]{8}$/', 'unique:'.User::class],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()]];
    }
}
