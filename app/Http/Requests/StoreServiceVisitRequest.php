<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceVisitRequest extends FormRequest
{
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
            'payment_method' => ['required', Rule::in(['Cash', 'DuitNow QR'])],

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

    /**
     * Per-line conditional rules (R2/R3) + fee existence (R1 source of truth).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('payment_method') === 'Cash' && ! $this->user()->hasPermission('collect_payment')) {
                $v->errors()->add('payment_method', 'Cash payment is not permitted for your account.');
            }
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $type = $line['service_type'] ?? null;
                $key = "lines.$i";
                if (! $type) {
                    continue;
                }
                $serviceType = \App\Models\ServiceType::where('name', $type)->first();
                if (! $serviceType) {
                    continue;
                }

                if ($serviceType->pricing_mode === 'flexible') {
                    if (empty($line['repair_desc'])) {
                        $v->errors()->add("$key.repair_desc", 'Describe the work done.');
                    }
                    if (! isset($line['rate']) || $line['rate'] === '' || $line['rate'] === null) {
                        $v->errors()->add("$key.rate", 'Enter a price.');
                    }
                    continue;
                }

                if (empty($line['unit_type'])) {
                    $v->errors()->add("$key.unit_type", 'Unit type is required for this service.');
                    continue;
                }

                $feeQuery = \App\Models\ServiceFee::where('service_type_id', $serviceType->id)
                    ->where('unit_type', $line['unit_type']);

                if ($serviceType->pricing_mode === 'hp_tiered') {
                    if (empty($line['hp_value'])) {
                        $v->errors()->add("$key.hp_value", 'HP is required for this service.');
                        continue;
                    }
                    $feeQuery->where('hp_value', (float) $line['hp_value']);
                } else {
                    $feeQuery->whereNull('hp_value');
                }

                if (! $feeQuery->exists()) {
                    $label = $line['unit_type'] . ($serviceType->pricing_mode === 'hp_tiered' ? " · {$line['hp_value']} HP" : '');
                    $field = $serviceType->pricing_mode === 'hp_tiered' ? 'hp_value' : 'unit_type';
                    $v->errors()->add("$key.$field", "No fee configured for {$type} · {$label}.");
                }
            }
        });
    }
}
