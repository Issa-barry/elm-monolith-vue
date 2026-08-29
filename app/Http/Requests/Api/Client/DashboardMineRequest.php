<?php

namespace App\Http\Requests\Api\Client;

use App\Services\Client\ClientEarningsService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Règles identiques au dashboard Inertia — définies UNE FOIS sur
 * ClientEarningsService::filterValidationRules(), jamais dupliquées ici.
 * Uniquement pour que ces filtres soient visibles dans la doc OpenAPI générée
 * (query params typés, 422 documenté) — `ClientEarningsService::resolveFilters()`
 * continue de (re)valider en interne, ce type-hint ne change aucun calcul.
 */
class DashboardMineRequest extends FormRequest
{
    public function rules(): array
    {
        return ClientEarningsService::filterValidationRules();
    }
}
