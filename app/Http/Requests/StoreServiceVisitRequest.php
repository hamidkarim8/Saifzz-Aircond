<?php

namespace App\Http\Requests;

use App\Models\ServiceFee;
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

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_type' => ['required', Rule::in(self::SERVICE_TYPES)],
            'lines.*.unit_type' => ['nullable', Rule::in(self::UNIT_TYPES)],
            'lines.*.gas_option' => ['nullable', Rule::in(self::GAS_OPTIONS)],
            'lines.*.repair_desc' => ['nullable', 'string', 'max:1000'],
            'lines.*.units' => ['required', 'integer', 'min:1'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.next_service_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Per-line conditional rules (R2/R3) + fee existence (R1 source of truth).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $type = $line['service_type'] ?? null;
                $key = "lines.$i";

                if (in_array($type, self::UNIT_TYPE_SERVICES, true) && empty($line['unit_type'])) {
                    $v->errors()->add("$key.unit_type", 'Unit type is required for this service.');
                }
                if ($type === 'Gas Top-Up' && empty($line['gas_option'])) {
                    $v->errors()->add("$key.gas_option", 'Gas option is required.');
                }
                if ($type === 'Repair') {
                    if (empty($line['repair_desc'])) {
                        $v->errors()->add("$key.repair_desc", 'Describe the repair.');
                    }
                    if (! isset($line['rate']) || $line['rate'] === '' || $line['rate'] === null) {
                        $v->errors()->add("$key.rate", 'Enter a price for this repair.');
                    }
                } elseif ($type) {
                    // R1 — a matching fee must exist so the rate can be snapshotted server-side.
                    $option = $type === 'Gas Top-Up' ? ($line['gas_option'] ?? null) : ($line['unit_type'] ?? null);
                    if ($option && ! ServiceFee::where('service_type', $type)->where('option', $option)->exists()) {
                        $v->errors()->add("$key.service_type", "No fee configured for {$type} · {$option}.");
                    }
                }
            }
        });
    }

    public const SERVICE_TYPES = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot'];
    public const UNIT_TYPES = ['Wall Mounted', 'Cassette'];
    public const GAS_OPTIONS = ['20 PSI', 'Half Top-Up', 'Full Top-Up'];

    /** Services that carry a unit type AND a next-service date (R2). */
    public const UNIT_TYPE_SERVICES = ['Cleaning', 'Installation', 'Troubleshoot'];
}
