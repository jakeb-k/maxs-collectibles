<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use Concerns\NormalizesAccountDetails;
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return ['first_name' => ['required', 'string', 'max:255'], 'last_name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^04[0-9]{8}$/', Rule::unique(User::class)->ignore($this->user()->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            'street' => ['nullable', 'required_with:city,state,postcode', 'string', 'max:255'],
            'city' => ['nullable', 'required_with:street,state,postcode', 'string', 'max:100'],
            'state' => ['nullable', 'required_with:street,city,postcode', 'string', 'in:NSW,QLD,SA,TAS,VIC,WA,ACT,NT'],
            'postcode' => ['nullable', 'required_with:street,city,state', 'string', 'regex:/^[0-9]{4}$/']];
    }
}
