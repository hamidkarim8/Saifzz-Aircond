<?php

namespace App\Http\Requests\Concerns;

trait ValidatesServiceLines
{
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
