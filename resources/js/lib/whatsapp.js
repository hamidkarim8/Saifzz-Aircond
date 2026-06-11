// Module 11 — single client-side builder for wa.me click-to-chat links.
// Mirrors App\Services\Notifications\WhatsApp (PHP) — keep the two in sync.

/**
 * Normalize a Malaysian phone to wa.me digits: strip non-digits, drop the
 * leading 0 and prefix the 60 country code (kept as-is when already 60-prefixed).
 */
export const waNumber = (phone) => {
    const digits = (phone ?? '').replace(/\D/g, '');
    if (!digits) return '';
    if (digits.startsWith('60')) return digits;
    return '60' + digits.replace(/^0+/, '');
};

/** Click-to-chat link, optionally with a pre-filled message. */
export const waLink = (phone, text = null) => {
    const url = `https://wa.me/${waNumber(phone)}`;
    return text === null ? url : `${url}?text=${encodeURIComponent(text)}`;
};
