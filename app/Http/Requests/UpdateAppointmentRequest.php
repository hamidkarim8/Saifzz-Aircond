<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Validation\Rule;

/**
 * Same shape and authorization as creating one — edits replace every field.
 *
 * Unlike create (which always starts an appointment as 'pending'), the edit
 * form lets an all-data user (admin) set the status directly — an intentional
 * override with no transition guard (the guarded lifecycle path is
 * updateStatus()). Scoped technicians cannot set status here: the rule and the
 * persisted attribute are both gated on seesAllData().
 */
class UpdateAppointmentRequest extends StoreAppointmentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (! $this->user()?->seesAllData()) {
            return parent::rules();
        }

        return parent::rules() + [
            'status' => ['sometimes', Rule::in(Appointment::STATUSES)],
        ];
    }

    /**
     * Adds the optional status override to the persistable attributes. CREATE
     * never reaches this override, so new appointments always start 'pending'.
     *
     * @return array<string, mixed>
     */
    public function appointmentData(): array
    {
        $data = parent::appointmentData();

        if ($this->user()?->seesAllData() && $this->filled('status')) {
            $data['status'] = $this->input('status');
        }

        return $data;
    }
}
