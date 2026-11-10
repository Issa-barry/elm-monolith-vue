<?php

namespace App\Http\Requests\Contacts;

use App\Models\Personne;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Identité d'une fiche Personne modifiée depuis Contacts (ADR 0060, lot 7a, `contacts.update`) :
 * mêmes règles et normalisations que la création (lot 3). La visibilité de la fiche est vérifiée
 * par le contrôleur (404).
 */
class UpdatePersonneContactRequest extends FormRequest
{
    use ReglesContact;

    public function authorize(): bool
    {
        return $this->user()->can('modifierIdentite', Personne::class);
    }

    public function rules(): array
    {
        return [
            'prenom' => ['nullable', 'string', 'max:100', 'required_without:nom_complet'],
            'nom' => ['nullable', 'string', 'max:100', 'required_without:nom_complet'],
            'nom_complet' => ['nullable', 'string', 'max:150'],
            'surnom' => ['nullable', 'string', 'max:100'],
            ...$this->reglesCoordonnees(),
        ];
    }

    public function messages(): array
    {
        return [
            'prenom.required_without' => 'Le prénom est obligatoire (ou le nom complet).',
            'nom.required_without' => 'Le nom est obligatoire (ou le nom complet).',
            ...$this->messagesContact(),
        ];
    }

    /** @return array<string, mixed> */
    public function identite(): array
    {
        $texte = fn (string $cle) => ($v = trim((string) $this->input($cle, ''))) === '' ? null : $v;
        $titre = fn (?string $v) => $v === null ? null : mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        $coordonnees = $this->coordonnees();
        // Position GPS non saisie par l'écran : jamais effacée par son absence.
        if (! $this->has('latitude')) {
            unset($coordonnees['latitude'], $coordonnees['longitude']);
        }

        return [
            'prenom' => $titre($texte('prenom')),
            'nom' => ($nom = $texte('nom')) === null ? null : mb_strtoupper($nom, 'UTF-8'),
            'nom_complet' => $titre($texte('nom_complet')),
            'surnom' => $texte('surnom'),
            ...$coordonnees,
        ];
    }
}
