<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $contact = $this->input('contact', []);
        $address = $this->input('address', []);
        if (is_array($contact)) {
            $email = $contact['email'] ?? $this->user()?->email;
            if (is_string($email)) $contact['email'] = strtolower(trim($email));
            foreach (['first_name', 'last_name'] as $key) if (is_string($contact[$key] ?? null)) $contact[$key] = preg_replace('/\s+/u', ' ', trim($contact[$key]));
            if (is_string($contact['mobile'] ?? null)) {
                $mobile = preg_replace('/[\s()\-]+/', '', $contact['mobile']);
                if (str_starts_with($mobile, '+614')) $mobile = '0'.substr($mobile, 3);
                elseif (str_starts_with($mobile, '614') && strlen($mobile) === 11) $mobile = '0'.substr($mobile, 2);
                $contact['mobile'] = $mobile;
            }
        }
        if (is_array($address)) {
            foreach (['street', 'city', 'state', 'postcode', 'country'] as $key) {
                if (is_string($address[$key] ?? null)) $address[$key] = preg_replace('/\s+/u', ' ', trim($address[$key]));
            }
            if (is_string($address['state'] ?? null)) $address['state'] = strtoupper($address['state']);
            if (is_string($address['country'] ?? null)) {
                $address['country'] = strtoupper($address['country']);
                if ($address['country'] === 'AUSTRALIA') $address['country'] = 'AU';
            }
            $address['country'] ??= 'AU';
        }
        $promo = $this->input('promo_code');
        if (is_string($promo)) $promo = strtoupper(trim($promo));
        $this->merge(['contact' => $contact, 'address' => $address, 'promo_code' => $promo === '' ? null : $promo]);
    }

    public function rules(): array
    {
        $checkout = $this->routeIs('checkout.store');
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:99'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.selection' => ['present', 'array', 'max:20'],
            'items.*.selection.*' => ['required', 'string', 'max:200'],
            'promo_code' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'array'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.state' => ['required', Rule::in(['QLD', 'NSW', 'VIC', 'ACT', 'SA', 'WA', 'NT', 'TAS'])],
            'address.postcode' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
            'address.country' => ['required', Rule::in(['AU'])],
            'contact' => ['required', 'array'],
            'contact.email' => ['required', 'email:rfc', 'max:255'],
            'contact.first_name' => [$checkout ? 'required' : 'nullable', 'string', 'max:100'],
            'contact.last_name' => [$checkout ? 'required' : 'nullable', 'string', 'max:100'],
            'contact.mobile' => [$checkout ? 'required' : 'nullable', 'string', 'regex:/^04[0-9]{8}$/'],
            'attempt_key' => [$checkout ? 'required' : 'sometimes', 'uuid'],
            'quote_token' => [$checkout ? 'required' : 'nullable', 'string', 'max:10000'],
        ];
    }

    public function normalized(): array
    {
        $data = $this->validated();
        $lines = [];
        foreach ($data['items'] as $index => $item) {
            // Form integers can be decimal digit strings; floats/booleans never become quantities or IDs.
            foreach (['product_id', 'quantity'] as $key) {
                if (!is_int($item[$key]) && !(is_string($item[$key]) && ctype_digit($item[$key]))) {
                    throw ValidationException::withMessages(["items.$index.$key" => 'Use an integer.']);
                }
            }
            $selection = $item['selection']; ksort($selection, SORT_STRING);
            $key = ((int) $item['product_id']).':'.json_encode($selection, JSON_THROW_ON_ERROR);
            $lines[$key] ??= ['product_id' => (int) $item['product_id'], 'quantity' => 0, 'selection' => $selection];
            $lines[$key]['quantity'] += (int) $item['quantity'];
            if ($lines[$key]['quantity'] > 999999) throw ValidationException::withMessages(['items' => 'Combined quantity exceeds the supported limit.']);
        }
        ksort($lines, SORT_NATURAL);
        return ['items' => array_values($lines), 'promo_code' => $data['promo_code'] ?? null,
            'address' => array_intersect_key($data['address'], array_flip(['street', 'city', 'state', 'postcode', 'country'])),
            'contact' => array_intersect_key($data['contact'], array_flip(['first_name', 'last_name', 'email', 'mobile']))];
    }
}
