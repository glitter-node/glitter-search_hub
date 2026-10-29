<?php

namespace Modules\Glitter\SearchHub\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'supported_types' => ['required', 'array', 'min:1'],
            'supported_types.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{1,63}$/'],
        ];
    }
}
