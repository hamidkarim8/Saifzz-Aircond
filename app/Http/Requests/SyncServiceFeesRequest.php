<?php

namespace App\Http\Requests;

use App\Models\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncServiceFeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit_fees');
    }

    public function rules(): array
    {
        $mode = $this->input('pricing_mode');

        return [
            'pricing_mode' => ['required', Rule::in(ServiceType::MODES)],
            'fees' => ['array', Rule::requiredIf($mode !== 'flexible')],
            'fees.*.unit_type' => ['required_with:fees.*', 'string', 'max:255'],
            'fees.*.price' => ['required_with:fees.*', 'numeric', 'min:0'],
            'fees.*.hp_value' => $this->hpValueRules($mode),
        ];
    }

    private function hpValueRules(string $mode): array
    {
        if ($mode === 'hp_tiered') {
            return ['required', 'numeric', 'min:0.5', 'max:20'];
        }

        // flat / flexible: hp_value must be absent or null
        return [
            'nullable',
            function ($attribute, $value, $fail) {
                if ($value !== null) {
                    $fail('The ' . $attribute . ' field is prohibited.');
                }
            },
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $seen = [];
            foreach ((array) $this->input('fees', []) as $i => $fee) {
                $key = ($fee['unit_type'] ?? '') . '|' . ($fee['hp_value'] ?? '');
                if (isset($seen[$key])) {
                    $v->errors()->add('fees', 'Duplicate unit type / HP combination.');
                }
                $seen[$key] = true;
            }
        });
    }
}
