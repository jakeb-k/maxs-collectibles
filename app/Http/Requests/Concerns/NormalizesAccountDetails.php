<?php

namespace App\Http\Requests\Concerns;

trait NormalizesAccountDetails
{
    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['first_name', 'last_name', 'email', 'mobile', 'street', 'city', 'state', 'postcode'] as $field) {
            if (!$this->exists($field)) continue;
            $value = $this->input($field);
            if (is_string($value)) {
                $value = preg_replace('/\s+/', ' ', trim($value));
                if ($field === 'email') $value = strtolower($value);
                if ($field === 'state') $value = strtoupper($value);
                if ($field === 'mobile') {
                    $value = preg_replace('/[\s()\-]/', '', $value);
                    if (str_starts_with($value, '+614')) $value = '0'.substr($value, 3);
                    elseif (str_starts_with($value, '614')) $value = '0'.substr($value, 2);
                }
                if ($value === '') $value = null;
            }
            $values[$field] = $value;
        }
        $this->merge($values);
    }
}
