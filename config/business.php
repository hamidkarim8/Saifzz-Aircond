<?php

return [
    'name' => env('BUSINESS_NAME', 'Saifzz Aircond Services'),
    'address' => env('BUSINESS_ADDRESS', 'No. 12, Jalan Teknologi, KL'),
    'phone' => env('BUSINESS_PHONE', '016-635 4563 / 016-281 5887'),
    // Days from invoice issue until payment is due (mockup shows a 7-day window).
    'invoice_due_days' => (int) env('BUSINESS_INVOICE_DUE_DAYS', 7),
];
