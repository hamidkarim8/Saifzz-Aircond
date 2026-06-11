<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('set_appointment');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Loosely linked — an appointment may be booked for a lead with no client record yet.
            'client_id' => ['nullable', 'exists:clients,id'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'service_type' => ['required', Rule::in(Appointment::SERVICE_TYPES)],
            'units' => ['nullable', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'phone' => ['required', 'string', 'regex:/^01\d-?\d{7,8}$/'],
            'address' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The two user-facing fields combined into a single persistable datetime.
     */
    public function datetime(): string
    {
        return $this->input('date').' '.$this->input('time');
    }

    /**
     * Persistable attributes (date/time already folded into `datetime`).
     *
     * @return array<string, mixed>
     */
    public function appointmentData(): array
    {
        return [
            'client_id' => $this->input('client_id'),
            'datetime' => $this->datetime(),
            'service_type' => $this->input('service_type'),
            'units' => $this->input('units'),
            'amount' => $this->input('amount'),
            'phone' => $this->input('phone'),
            'address' => $this->input('address'),
            'notes' => $this->input('notes'),
        ];
    }
}
