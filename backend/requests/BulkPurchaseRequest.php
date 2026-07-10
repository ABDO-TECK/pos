<?php

namespace App\Requests;

use App\Core\FormRequest;

class BulkPurchaseRequest extends FormRequest {
    public function rules(): array {
        return [
            'supplier_id'    => 'required|integer',
            'items'          => 'required|array',
            'notes'          => 'string',
            'driver_name'    => 'string',
            'vehicle_number' => 'string',
            'delivery_date'  => 'string',
            'delivery_notes' => 'string',
        ];
    }
}
