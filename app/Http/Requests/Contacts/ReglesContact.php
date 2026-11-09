<?php

namespace App\Http\Requests\Contacts;

use App\Enums\PrestataireType;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Règles partagées des formulaires du référentiel Contacts (ADR 0060, lot 3) : coordonnées, première
 * fonction, et téléphone normalisé comme pour Nouvel employé (libphonenumber), pour qu'un même
 * numéro soit toujours reconnu.
 */
trait ReglesContact
{
    /** @var array{telephone: string, code_pays: string, indicatif: string, pays: string}|null */
    private ?array $numero = null;

    /** @return array<string, mixed> */
    protected function reglesCoordonnees(): array
    {
        return [
            'telephone' => ['required', 'string', 'max:30'],
            'telephone_secondaire' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'ville' => ['required', 'string', 'max:100'],
            'quartier' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    /** @return array<string, mixed> */
    protected function reglesFonction(): array
    {
        return [
            'fonction' => ['required', 'string', Rule::in(app(FonctionsContactEcriture::class)->fonctionsCreables($this->user()))],
            // Lot 6b : qualifications multiples ; l'ancien champ `type` (un code) reste accepté.
            'qualification_ids' => ['nullable', 'array', 'max:20'],
            'qualification_ids.*' => ['required', 'string', 'max:26'],
            'type' => ['nullable', Rule::enum(PrestataireType::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    protected function messagesContact(): array
    {
        return [
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone_secondaire.regex' => 'Le téléphone secondaire est invalide.',
            'ville.required' => 'La ville est obligatoire.',
            'email.email' => "L'adresse e-mail est invalide.",
            'fonction.required' => 'Choisissez la fonction.',
            'fonction.in' => 'Vous ne pouvez pas créer cette fonction.',
            'type.enum' => 'Qualification inconnue.',
        ];
    }

    /** Numéro invalide pour son pays : refusé avant toute écriture. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('fonction') === 'prestataire' && empty($this->input('qualification_ids')) && empty($this->input('type'))) {
                $validator->errors()->add('qualification_ids', 'Choisissez au moins une qualification du prestataire.');
            }
            if ($validator->errors()->has('telephone')) {
                return;
            }
            $this->numero = RechercheEmployeParTelephone::normaliser((string) $this->input('telephone'));
            if ($this->numero === null) {
                $validator->errors()->add('telephone', 'Ce numéro n’est pas valide. Vérifiez le pays et le nombre de chiffres.');
            }
        }];
    }

    /** @return array{telephone: string, code_pays: string, indicatif: string, pays: string} */
    public function numero(): array
    {
        return $this->numero ?? RechercheEmployeParTelephone::normaliser((string) $this->input('telephone'));
    }

    /** @return array<string, mixed> coordonnées communes à Personne et Entreprise */
    protected function coordonnees(): array
    {
        $numero = $this->numero();
        $texte = fn (string $cle) => ($v = trim((string) $this->input($cle, ''))) === '' ? null : $v;
        $titre = fn (?string $v) => $v === null ? null : mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return [
            'telephone' => $numero['telephone'],
            'telephone_secondaire' => $texte('telephone_secondaire'),
            'code_pays' => $numero['code_pays'],
            'code_phone_pays' => $numero['indicatif'],
            'pays' => $numero['pays'],
            'email' => $texte('email'),
            'ville' => $titre($texte('ville')),
            'quartier' => $titre($texte('quartier')),
            'adresse' => $texte('adresse'),
            'latitude' => $this->input('latitude'),
            'longitude' => $this->input('longitude'),
        ];
    }

    /** @return array{type: ?string, notes: ?string} */
    public function donneesFonction(): array
    {
        return [
            'type' => $this->input('type'),
            'qualification_ids' => $this->has('qualification_ids') ? array_values((array) $this->input('qualification_ids')) : null,
            'notes' => $this->input('notes'),
        ];
    }
}
