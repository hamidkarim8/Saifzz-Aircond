<?php

namespace App\Support;

class BrandAssets
{
    /**
     * Base64 data-URI of the web logo — renders in both the HTML document
     * view and dompdf (which cannot reliably resolve asset URLs).
     * Cached per-request.
     */
    public static function logoDataUri(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached ?: null;
        }

        $path = public_path('img/logo-256.png');
        if (! is_file($path)) {
            $cached = '';
            return null;
        }

        $cached = 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        return $cached;
    }
}
