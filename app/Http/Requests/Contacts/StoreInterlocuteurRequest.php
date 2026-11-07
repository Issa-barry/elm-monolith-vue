<?php

namespace App\Http\Requests\Contacts;

use App\Models\EntrepriseTierce;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ajout d'un interlocuteur (ADR 0060, lot 4). Téléphone obligatoire, normalisé comme partout
 * (libphonenumber). Sans `personne_confirmee_id`, une nouvelle Personne est créée (nom obligatoire,
 * vérifié par InterlocuteurService) ; avec, c'est la confirmation explicite « même personne ».
 */
class StoreInterlocuteurRequest extends FormRequest
{
    /** @var array{telephone: string, code_pays: string, indicatif: string, pays: string}|null */
    private ?array $numero = null;

    public function authorize(): bool
    {
        return $this->user()->can('gererInterlocuteurs', EntrepriseTierce::class);
    }

    public function rules(): array
    {
        return [
            'telephone' => ['required', 'string', 'max:30'],
            'personne_confirmee_id' => ['nullable', 'string', 'max:26'],
            'nom_complet' => ['nullable', 'string', 'max:150'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'nom' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'ville' => ['nullable', 'string', 'max:100'],
            'quartier' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'fonction' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'principal' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'telephone.required' => "Le numéro de téléphone de l'interlocuteur est obligatoire.",
            'email.email' => "L'adresse e-mail est invalide.",
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('telephone')) {
                return;
            }
            $this->numero = RechercheEmployeParTelephone::normaliser((string) $this->input('telephone'));
            if ($this->numero === null) {
                $validator->errors()->add('telephone', 'Ce numéro n’est pas valide. Vérifiez le pays et le nombre de chiffres.');
            }
        }];
    }

    /** @return array<string, mixed> données transmises à InterlocuteurService::ajouter() */
    public function donnees(): array
    {
        $numero = $this->numero ?? RechercheEmployeParTelephone::normaliser((string) $this->input('telephone'));
        $texte = fn (string $cle) => ($v = trim((string) $this->input($cle, ''))) === '' ? null : $v;
        $titre = fn (?string $v) => $v === null ? null : mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return [
            'telephone' => $numero['telephone'],
            'code_pays' => $numero['code_pays'],
            'code_phone_pays' => $numero['indicatif'],
            'pays' => $numero['pays'],
            'nom_complet' => $titre($texte('nom_complet')),
            'prenom' => $titre($texte('prenom')),
            'nom' => ($nom = $texte('nom')) === null ? null : mb_strtoupper($nom, 'UTF-8'),
            'email' => $texte('email'),
            'ville' => $titre($texte('ville')),
            'quartier' => $titre($texte('quartier')),
            'adresse' => $texte('adresse'),
            'latitude' => $this->input('latitude'),
            'longitude' => $this->input('longitude'),
            'fonction' => $texte('fonction'),
            'notes' => $texte('notes'),
        ];
    }
}
