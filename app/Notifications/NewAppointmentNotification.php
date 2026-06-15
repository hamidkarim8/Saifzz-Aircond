<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewAppointmentNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Appointment $appointment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $client = $this->appointment->client;
        return [
            'appointment_id' => $this->appointment->id,
            'client_name'    => $client?->name ?? '—',
            'serial_no'      => $client?->serial_no ?? '—',
            'datetime'       => $this->appointment->datetime?->toDateTimeString(),
            'notes'          => $this->appointment->notes,
        ];
    }
}
