<?php

namespace App\Requests;

use App\Core\FormRequest;

class InventoryAdjustRequest extends FormRequest {
    public function rules(): array {
        return [
            'quantity' => 'required|numeric|min_value:0',
        ];
    }
}
