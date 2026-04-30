<?php

namespace App\Requests;

use App\Core\FormRequest;


class CustomerRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'            => 'required|string',
            'phone'           => 'string',
            'address'         => 'string',
            'initial_balance' => 'numeric',
        ];
    }
}
