<?php

namespace App\Http\Requests;

use App\Enums\TypeImportFlotte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportFlotteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('imports-flotte.create');
    }

    public function rules(): array
    {
        return [
            'fichier' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
            // Absent = 'flotte' (compat ascendante avec l'ancien formulaire à un seul mode).
            'type' => ['nullable', Rule::in(TypeImportFlotte::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'fichier.required' => 'Le fichier est obligatoire.',
            'fichier.mimes' => 'Le fichier doit être un fichier Excel (.xlsx ou .xls).',
            'fichier.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
            'type.in' => 'Type d\'import invalide.',
        ];
    }

    public function typeImport(): TypeImportFlotte
    {
        return TypeImportFlotte::from($this->string('type')->value() ?: TypeImportFlotte::FLOTTE->value);
    }
}
