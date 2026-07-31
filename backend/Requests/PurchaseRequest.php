<?php

namespace App\Requests;

use App\Core\FormRequest;

class PurchaseRequest extends FormRequest {
    public function rules(): array {
        return [
            'supplier_id' => 'required|integer',
            'product_id'  => 'required|integer',
            'quantity'    => 'required|numeric',
            'cost'        => 'required|numeric',
        ];
    }
}
