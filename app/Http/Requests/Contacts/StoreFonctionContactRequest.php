<?php

namespace App\Http\Requests\Contacts;

use App\Models\Personne;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajout explicite d'une fonction à une fiche existante (ADR 0060, lot 3) : c'est le geste de
 * confirmation « C'est la même personne ». La visibilité de la fiche est vérifiée par le contrôleur.
 */
class StoreFonctionContactRequest extends FormRequest
{
    use ReglesContact;

    public function authorize(): bool
    {
        return $this->user()->can('create', Personne::class);
    }

    public function rules(): array
    {
        return $this->reglesFonction();
    }

    public function messages(): array
    {
        return $this->messagesContact();
    }

    /** Pas de téléphone dans ce formulaire : aucune normalisation à faire. */
    public function after(): array
    {
        return [];
    }
}
