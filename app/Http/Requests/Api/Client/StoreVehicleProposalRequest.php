<?php

namespace App\Http\Requests\Api\Client;

use App\Services\Client\VehicleProposalService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Règles identiques à l'espace client Inertia — définies UNE FOIS sur
 * VehicleProposalService, jamais dupliquées ici (cf. docblock du service).
 */
class StoreVehicleProposalRequest extends FormRequest
{
    public function rules(): array
    {
        return VehicleProposalService::validationRules();
    }

    public function messages(): array
    {
        return VehicleProposalService::validationMessages();
    }
}
