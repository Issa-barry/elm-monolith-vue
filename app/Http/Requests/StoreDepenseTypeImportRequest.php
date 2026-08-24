<?php

namespace App\Http\Requests;

use App\Models\DepenseType;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepenseTypeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DepenseType::class);
    }

    public function rules(): array
    {
        return [
            'fichier' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'fichier.required' => 'Le fichier est obligatoire.',
            'fichier.mimes' => 'Le fichier doit être un fichier Excel (.xlsx ou .xls).',
            'fichier.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ];
    }
}
