<?php

namespace App\Http\Requests\Contacts;

use App\Models\EntrepriseTierce;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Identité d'une fiche Entreprise modifiée depuis Contacts (ADR 0060, lot 7a, `contacts.update`).
 * Téléphone et ville facultatifs : une entreprise créée depuis un propriétaire n'en a pas forcément
 * (lot 5). Numéro normalisé comme à la création. La visibilité est vérifiée par le contrôleur (404).
 */
class UpdateEntrepriseContactRequest extends FormRequest
{
    /** @var array{telephone: string, code_pays: string, indicatif: string, pays: string}|null */
    private ?array $numero = null;

    public function authorize(): bool
    {
        return $this->user()->can('modifierIdentite', EntrepriseTierce::class);
    }

    public function rules(): array
    {
        return [
            'raison_sociale' => ['required', 'string', 'max:200'],
            'nom_commercial' => ['nullable', 'string', 'max:150'],
            'identifiant_fiscal' => ['nullable', 'string', 'max:50'],
            'rccm' => ['nullable', 'string', 'max:50'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'telephone_secondaire' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'ville' => ['nullable', 'string', 'max:100'],
            'quartier' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function messages(): array
    {
        return [
            'raison_sociale.required' => 'La raison sociale est obligatoire.',
            'telephone_secondaire.regex' => 'Le téléphone secondaire est invalide.',
            'email.email' => "L'adresse e-mail est invalide.",
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $saisie = trim((string) $this->input('telephone', ''));
            if ($saisie === '' || $validator->errors()->has('telephone')) {
                return;
            }
            $this->numero = RechercheEmployeParTelephone::normaliser($saisie);
            if ($this->numero === null) {
                $validator->errors()->add('telephone', 'Ce numéro n’est pas valide. Vérifiez le pays et le nombre de chiffres.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function identite(): array
    {
        $texte = fn (string $cle) => ($v = trim((string) $this->input($cle, ''))) === '' ? null : $v;
        $titre = fn (?string $v) => $v === null ? null : mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return [
            'raison_sociale' => $texte('raison_sociale'),
            'nom_commercial' => $texte('nom_commercial'),
            'identifiant_fiscal' => $texte('identifiant_fiscal'),
            'rccm' => $texte('rccm'),
            'telephone' => $this->numero['telephone'] ?? null,
            ...($this->numero !== null ? ['code_pays' => $this->numero['code_pays'], 'code_phone_pays' => $this->numero['indicatif'], 'pays' => $this->numero['pays']] : []),
            'telephone_secondaire' => $texte('telephone_secondaire'),
            'email' => $texte('email'),
            'ville' => $titre($texte('ville')),
            'quartier' => $titre($texte('quartier')),
            'adresse' => $texte('adresse'),
            // Position GPS non saisie par l'écran : jamais effacée par son absence.
            ...($this->has('latitude') ? ['latitude' => $this->input('latitude'), 'longitude' => $this->input('longitude')] : []),
        ];
    }
}
