<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceFeeRequest extends FormRequest
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
            'service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
            'option' => ['nullable', 'string', 'max:255'],
            'pricing_mode' => ['required', Rule::in(self::MODES)],
            // Flexible (Repair) carries no rate; everything else needs one.
            'rate' => ['nullable', 'required_unless:pricing_mode,flexible', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $exists = \App\Models\ServiceFee::where('service_type', $this->service_type)
                ->where('option', $this->option)
                ->exists();
            if ($exists) {
                $v->errors()->add('option', 'A fee for this service type and option already exists.');
            }
        });
    }

    public const MODES = ['fixed_per_unit', 'tiered', 'flexible'];
}
