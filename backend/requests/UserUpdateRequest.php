<?php

namespace App\Requests;

use App\Core\FormRequest;

class UserUpdateRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'      => 'required|string',
            'email'     => 'required|email',
            'role'      => 'string',
            'is_active' => 'numeric',
            'password'  => 'string',
        ];
    }
}
