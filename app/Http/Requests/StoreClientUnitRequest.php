<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_units');
    }

    public function rules(): array
    {
        return [
            'label'            => ['required', 'string', 'max:100'],
            'unit_type'        => ['required', Rule::in(['Wall Mounted', 'Cassette'])],
            'hp'               => ['nullable', Rule::in([0.75, 1.0, 1.5, 2.0, 2.5])],
            'brand'            => ['nullable', 'string', 'max:100'],
            'model'            => ['nullable', 'string', 'max:100'],
            'serial_no'        => ['nullable', 'string', 'max:100'],
            'refrigerant_type' => ['nullable', Rule::in(['R32', 'R410A', 'R22'])],
            'notes'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
