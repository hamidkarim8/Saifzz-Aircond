<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $existing = \App\Models\TenantGateway::where('tenant_id', $this->user()->id)->exists();

        return [
            'api_token'  => $existing ? ['nullable', 'string'] : ['required', 'string'],
            'portal_key' => $existing ? ['nullable', 'string'] : ['required', 'string'],
            'api_secret' => $existing ? ['nullable', 'string'] : ['required', 'string'],
        ];
    }
}
