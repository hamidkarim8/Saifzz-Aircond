<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServiceLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceVisitRequest extends FormRequest
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
        return [
            'client_mode' => ['required', Rule::in(['existing', 'new'])],
            'client_id' => ['nullable', 'required_if:client_mode,existing', 'exists:clients,id'],
            'new_client.name' => ['nullable', 'required_if:client_mode,new', 'string', 'max:255'],
            'new_client.phone' => ['nullable', 'required_if:client_mode,new', 'string', 'regex:/^01\d-?\d{7,8}$/'],
            'new_client.address' => ['nullable', 'required_if:client_mode,new', 'string', 'max:1000'],

            'visit_date' => ['required', 'date'],
            'warranty_months' => ['required', 'integer', 'between:0,6'],

            'technician_id' => ['nullable', 'integer', 'exists:users,id'],

            'appointment_id' => [
                'nullable', 'integer',
                Rule::exists('appointments', 'id')->where(function ($q) {
                    $tenantId = $this->user()?->tenantId();
                    if ($tenantId !== null) {
                        $q->where('tenant_id', $tenantId);
                    }
                }),
            ],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
            'lines.*.unit_type' => ['nullable', 'string', 'max:255'],
            'lines.*.repair_desc' => ['nullable', 'string', 'max:1000'],
            'lines.*.units' => ['required', 'integer', 'min:1'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.next_service_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('client_units', 'id')->where('client_id', $this->input('client_id'))],
            'lines.*.hp_value' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($v) => $this->validateServiceLines($v));
    }
}
