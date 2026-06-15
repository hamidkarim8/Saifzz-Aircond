<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind can:manage_users; double-guard here.
        return $this->user()?->hasPermission('manage_users') ?? false;
    }

    public function rules(): array
    {
        $grantable = array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS));

        return [
            'presets' => ['required', 'array'],
            'presets.1' => ['present', 'array'],
            'presets.2' => ['present', 'array'],
            'presets.3' => ['present', 'array'],
            'presets.*.*' => ['string', Rule::in($grantable)],
        ];
    }
}
