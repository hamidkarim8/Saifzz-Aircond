<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by controller middleware
    }

    public function rules(): array
    {
        return [
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'ssm_no' => ['nullable', 'string', 'max:100'],
            'google_review_url' => ['nullable', 'url', 'max:500'],
            'google_review_qr' => ['nullable', 'image', 'max:2048'],
            'payment_qr' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
