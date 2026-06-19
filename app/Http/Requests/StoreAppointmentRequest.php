<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'customer_name' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'phone' => ['required', 'string', 'regex:/^01\d-?\d{7,8}$/'],
            'address' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
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
            'customer_name' => $this->input('client_id') ? null : $this->input('customer_name'),
            'datetime' => $this->datetime(),
            'phone' => $this->input('phone'),
            'address' => $this->input('address'),
            'notes' => $this->input('notes'),
        ];
    }
}
