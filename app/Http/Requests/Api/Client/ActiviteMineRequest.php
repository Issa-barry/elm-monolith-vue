<?php

namespace App\Http\Requests\Api\Client;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de `GET /v1/mobile/activite` — historique complet (pas seulement
 * "en cours") mêlant deux modèles à vocabulaire de statut distinct
 * (CommandeVente vs TransfertLogistique). `statut` n'a de sens qu'accompagné
 * de `type` (sinon on ne sait pas contre quel enum le valider) — l'exiger
 * explicitement plutôt que d'inventer une correspondance entre les deux
 * vocabulaires ou d'ignorer silencieusement le filtre.
 */
class ActiviteMineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['vente', 'logistique'])],
            'statut' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }
                    $type = $this->input('type');
                    if ($type === null) {
                        $fail('Le filtre "statut" nécessite de préciser "type" (vente ou logistique) : les deux modèles ont des statuts différents.');

                        return;
                    }
                    $valides = array_column(
                        $type === 'vente' ? StatutCommandeVente::cases() : StatutTransfert::cases(),
                        'value'
                    );
                    if (! in_array($value, $valides, true)) {
                        $fail('Statut invalide pour ce type.');
                    }
                },
            ],
            'vehicule_id' => ['nullable', 'string'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return [
            'type' => $this->input('type'),
            'statut' => $this->input('statut'),
            'vehicule_id' => $this->input('vehicule_id'),
            'date_debut' => $this->input('date_debut'),
            'date_fin' => $this->input('date_fin'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }

    public function page(): int
    {
        return (int) ($this->input('page') ?? 1);
    }
}
