<?php

namespace App\Http\Requests;

/**
 * Same shape and authorization as creating one — edits replace every field.
 */
class UpdateAppointmentRequest extends StoreAppointmentRequest {}
