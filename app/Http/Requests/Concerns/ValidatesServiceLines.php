<?php

namespace App\Http\Requests\Concerns;

trait ValidatesServiceLines
{
    /**
     * Human-readable field names so errors don't leak raw keys like
     * "lines.0.service_type". Shared by Store + Update requests (extra keys for
     * fields a given request lacks are harmless — only used when that field errors).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => 'client',
            'new_client.name' => 'client name',
            'new_client.phone' => 'phone number',
            'new_client.address' => 'address',
            'visit_date' => 'service date',
            'warranty_months' => 'warranty',
            'technician_id' => 'technician',
            'lines.*.service_type' => 'service type',
            'lines.*.unit_type' => 'unit type',
            'lines.*.units' => 'units',
            'lines.*.rate' => 'price',
            'lines.*.discount' => 'discount',
            'lines.*.hp_value' => 'HP',
            'lines.*.next_service_date' => 'next service date',
            'lines.*.repair_desc' => 'description',
        ];
    }

    /**
     * Plain-English messages for the per-line rules. `:position` is the 1-based
     * line number (Laravel array-validation placeholder).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one service line.',
            'lines.min' => 'Add at least one service line.',
            'lines.*.service_type.required' => 'Select a service type for line :position.',
            'lines.*.service_type.exists' => 'Line :position has an unknown service type.',
            'lines.*.units.required' => 'Enter the number of units for line :position.',
            'lines.*.units.min' => 'Units for line :position must be at least 1.',
            'lines.*.units.integer' => 'Units for line :position must be a whole number.',
            'lines.*.rate.numeric' => 'Price for line :position must be a number.',
            'lines.*.discount.numeric' => 'Discount for line :position must be a number.',
        ];
    }

    /**
     * Per-line conditional rules (R2/R3) + fee existence (R1).
     * Shared by StoreServiceVisitRequest and UpdateServiceVisitRequest.
     *
     * Payment method is no longer selected at record creation (CHG-008); the real
     * method + collect_payment permission are enforced at the payment-collection
     * routes (payments.cash / payments.manualQr / payments.pay, all `can:collect_payment`).
     */
    protected function validateServiceLines($v): void
    {
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
    }
}
