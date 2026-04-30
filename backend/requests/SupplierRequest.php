<?php

namespace App\Requests;

use App\Core\FormRequest;


class SupplierRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'            => 'required|string',
            'phone'           => 'string',
            'email'           => 'string',
            'address'         => 'string',
            'initial_balance' => 'numeric',
        ];
    }
}
