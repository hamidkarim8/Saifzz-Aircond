<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServiceLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceVisitRequest extends FormRequest
{
    use ValidatesServiceLines;

    public function authorize(): bool
    {
        return $this->user()->can('record_service');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clientId = $this->route('serviceRecord')->client_id;

        return [
            'visit_date' => ['required', 'date'],
            'warranty_months' => ['required', 'integer', 'between:0,6'],
            'payment_method' => ['required', Rule::in(['Cash', 'DuitNow QR'])],
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
            'lines.*.unit_type' => ['nullable', 'string', 'max:255'],
            'lines.*.repair_desc' => ['nullable', 'string', 'max:1000'],
            'lines.*.units' => ['required', 'integer', 'min:1'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.next_service_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('client_units', 'id')->where('client_id', $clientId)],
            'lines.*.hp_value' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($v) => $this->validateServiceLines($v));
    }
}
