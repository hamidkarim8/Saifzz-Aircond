<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit_fees');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pricing_mode' => ['required', Rule::in(StoreServiceFeeRequest::MODES)],
            'rate' => ['nullable', 'required_unless:pricing_mode,flexible', 'numeric', 'min:0'],
        ];
    }
}
