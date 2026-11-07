<?php

namespace App\Http\Requests\Contacts;

use App\Models\EntrepriseTierce;
use Illuminate\Foundation\Http\FormRequest;

/** Nouvelle fiche Entreprise et sa première fonction (ADR 0060, lot 3). */
class StoreEntrepriseContactRequest extends FormRequest
{
    use ReglesContact;

    public function authorize(): bool
    {
        return $this->user()->can('create', EntrepriseTierce::class);
    }

    public function rules(): array
    {
        return [
            'raison_sociale' => ['required', 'string', 'max:200'],
            'nom_commercial' => ['nullable', 'string', 'max:200'],
            'rccm' => ['nullable', 'string', 'max:50'],
            'identifiant_fiscal' => ['nullable', 'string', 'max:50'],
            ...$this->reglesCoordonnees(),
            ...$this->reglesFonction(),
        ];
    }

    public function messages(): array
    {
        return [
            'raison_sociale.required' => 'La raison sociale est obligatoire.',
            ...$this->messagesContact(),
        ];
    }

    /** @return array<string, mixed> */
    public function identite(): array
    {
        $texte = fn (string $cle) => ($v = trim((string) $this->input($cle, ''))) === '' ? null : $v;

        return [
            'raison_sociale' => $texte('raison_sociale'),
            'nom_commercial' => $texte('nom_commercial'),
            'rccm' => $texte('rccm'),
            'identifiant_fiscal' => $texte('identifiant_fiscal'),
            'is_active' => true,
            ...$this->coordonnees(),
        ];
    }
}
