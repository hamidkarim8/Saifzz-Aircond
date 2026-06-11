<?php

namespace App\Services\Notifications;

/**
 * Module 11 — one place that turns a phone number into an outbound WhatsApp
 * channel. v1 is wa.me click-to-chat links (manual send); the Meta Cloud API
 * lands here later as a send() method behind the same service, so callers
 * (Reminders, Appointments, Portal) don't change where they ask for WhatsApp.
 *
 * Mirrored client-side by resources/js/lib/whatsapp.js — keep the two in sync.
 */
final class WhatsApp
{
    /**
     * Normalize a Malaysian phone to wa.me digits: strip non-digits, drop the
     * leading 0 and prefix the 60 country code (kept as-is when already 60-prefixed).
     */
    public function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '60')) {
            return $digits;
        }

        return '60'.ltrim($digits, '0');
    }

    /** Click-to-chat link, optionally with a pre-filled message. */
    public function link(?string $phone, ?string $text = null): string
    {
        $url = 'https://wa.me/'.$this->normalize($phone);

        return $text === null ? $url : $url.'?text='.rawurlencode($text);
    }
}
