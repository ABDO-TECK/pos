<?php

namespace App\Requests;

use App\Core\FormRequest;

class SaleRequest extends FormRequest {
    public function rules(): array {
        return [
            'items'          => 'required|array',
            'payment_method' => 'required|in:cash,card,vodafone_cash,instapay,other_wallet,credit',
            'discount'       => 'numeric',
            'amount_paid'    => 'numeric',
            'customer_id'    => 'integer',
            'invoice_id'     => 'integer',
            'status'         => 'in:completed,reserved',
            'driver_name'    => 'string',
            'vehicle_number' => 'string',
            'delivery_date'  => 'string',
            'delivery_notes' => 'string',
        ];
    }
}
