<?php

namespace App\Requests;

use App\Core\FormRequest;

class SettingsRequest extends FormRequest {
    public function rules(): array {
        return [
            'store_name'  => 'string|max:200',
            'tax_enabled' => 'in:0,1',
            'tax_rate'    => 'numeric',
            'loyalty_enabled' => 'in:0,1',
            'loyalty_points_per_rial' => 'numeric',
            'loyalty_rial_per_point' => 'numeric',
        ];
    }
}
