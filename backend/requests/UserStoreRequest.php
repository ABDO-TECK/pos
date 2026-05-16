<?php

namespace App\Requests;

use App\Core\FormRequest;

class UserStoreRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'     => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|min:6|strong_password',
            'role'     => 'in:admin,cashier',
        ];
    }
}
