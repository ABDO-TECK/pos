<?php

namespace App\Requests;

use App\Core\FormRequest;


class ProductRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'                => 'required',
            'price'               => 'required|numeric',
            'barcode'             => '',
            'box_barcode'         => '',
            'cost'                => 'numeric',
            'quantity'            => 'numeric',
            'low_stock_threshold' => 'numeric',
            'category_id'         => 'integer',
            'units_per_box'       => 'integer',
            'sell_by_weight'      => 'integer',
            'additional_barcodes' => 'array',
        ];
    }
}
