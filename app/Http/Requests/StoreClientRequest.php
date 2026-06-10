<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit_client');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Malaysian mobile: 01X-XXXXXXX (dash optional, 9–11 digits total)
            'phone' => ['required', 'string', 'regex:/^01\d-?\d{7,8}$/'],
            'address' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone must be a Malaysian mobile number, e.g. 012-3456789.',
        ];
    }
}
