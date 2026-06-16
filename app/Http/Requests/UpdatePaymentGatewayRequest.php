<?php
namespace App\Http\Requests;

use App\Models\TenantGateway;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentGatewayRequest extends FormRequest
{
    private ?bool $hasExisting = null;

    public function authorize(): bool
    {
        return true; // guarded by controller middleware
    }

    public function rules(): array
    {
        if ($this->hasExisting === null) {
            $this->hasExisting = TenantGateway::where('tenant_id', $this->user()->id)->exists();
        }

        return [
            'api_token'  => $this->hasExisting ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'],
            'portal_key' => $this->hasExisting ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'],
            'api_secret' => $this->hasExisting ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'],
        ];
    }
}
