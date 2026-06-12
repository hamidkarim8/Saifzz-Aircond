<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_users');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['nullable', 'array'],
            // Validates against ALL PERMISSIONS (including admin-only). Admin-only entries
            // pass validation but are silently dropped by grantPermission() — P1.
            'permissions.*' => ['string', Rule::in(User::PERMISSIONS)],
        ];
    }
}
