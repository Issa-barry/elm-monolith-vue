<?php

namespace App\Http\Requests\Rh;

use App\Services\Rh\JustificatifAbsenceStorage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saisie et modification d'une absence (docs/rh-absences.md). Forme des champs seulement : le
 * salarié, le motif, la période d'emploi et les chevauchements sont contrôlés par AbsenceService.
 * La durée n'est jamais reçue du navigateur : elle se déduit des dates.
 */
class EnregistrerAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Droits vérifiés par le contrôleur (policy) puis par AbsenceService (agence).
    }

    public function rules(): array
    {
        $creation = $this->route('absence') === null;

        return [
            'employe_id' => $creation ? ['required', 'string'] : ['prohibited'],
            'motif_absence_id' => ['required', 'string'],
            'date_debut' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
            'justificatif' => ['nullable', 'file', 'mimes:'.implode(',', JustificatifAbsenceStorage::MIMES), 'max:'.JustificatifAbsenceStorage::TAILLE_MAX_KO],
            'retirer_justificatif' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'employe_id.required' => 'Choisissez le salarié.',
            'employe_id.prohibited' => 'Le salarié d\'une absence ne se modifie pas : annulez-la et saisissez-la pour le bon salarié.',
            'motif_absence_id.required' => 'Le motif est obligatoire.',
            'date_debut.required' => 'La date de début est obligatoire.',
            'date_debut.date_format' => 'Date de début invalide.',
            'date_fin.required' => 'La date de fin est obligatoire.',
            'date_fin.date_format' => 'Date de fin invalide.',
            'date_fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'commentaire.max' => 'Le commentaire ne doit pas dépasser 2 000 caractères.',
            'justificatif.file' => 'Le justificatif doit être un fichier.',
            'justificatif.mimes' => 'Le justificatif doit être un PDF ou une image (JPG, PNG, WEBP).',
            'justificatif.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
        ];
    }
}
