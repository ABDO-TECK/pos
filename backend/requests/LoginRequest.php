<?php

namespace App\Requests;

use App\Core\FormRequest;

class LoginRequest extends FormRequest {
    public function rules(): array {
        return [
            'email'    => 'required|email',
            'password' => 'required',
        ];
    }
}
