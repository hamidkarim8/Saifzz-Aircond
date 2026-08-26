<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class BrandAssets
{
    /**
     * Public-disk URL for an uploaded QR, versioned on the file's mtime.
     *
     * QR filenames are fixed per tenant (qr/tenant-{id}.png), so re-uploading
     * changes the bytes but never the path — without the version the browser
     * keeps serving the old image forever. Every screen showing a stored QR
     * must go through here.
     */
    public static function qrUrl(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path).'?v='.Storage::disk('public')->lastModified($path);
    }

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
