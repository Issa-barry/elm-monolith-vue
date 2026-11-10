<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Champs « fiche Entreprise » des formulaires d'une fonction qui peut être une entreprise —
 * propriétaire entreprise (lot 5) et client professionnel (lot 7b), ADR 0060. À utiliser avec
 * App\Traits\PhoneHandlerTrait (longueur locale et format international du téléphone).
 */
trait SaisieEntrepriseLiee
{
    /** @return array<string, mixed> téléphone et NIF facultatifs (D2), choix explicite, confirmation D7 */
    protected function reglesEntrepriseLiee(): array
    {
        return [
            'telephone_entreprise' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'identifiant_fiscal' => 'nullable|string|max:50',
            'entreprise_choix' => 'nullable|string|max:30',
            'confirmer_modification_entreprise' => 'boolean',
        ];
    }

    /**
     * Identité de l'entreprise saisie dans le formulaire. Le téléphone de l'entreprise n'est jamais
     * celui du contact (D2) ; sans indicatif, il prend celui du pays choisi.
     *
     * @return array{raison_sociale: string, raison_sociale_saisie: string, telephone: ?string, identifiant_fiscal: ?string}
     */
    protected function identiteEntreprise(array $data, string $raisonSocialeSaisie): array
    {
        $telephone = null;
        $brut = trim((string) ($data['telephone_entreprise'] ?? ''));
        if ($brut !== '') {
            if (! str_starts_with($brut, '+')) {
                try {
                    $this->validateLocalPhoneLength(['telephone' => $brut, 'code_pays' => $data['code_pays'] ?? null]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['telephone_entreprise' => $e->errors()['telephone'][0]]);
                }
            }
            $telephone = $this->buildInternationalPhone($brut, $data['code_phone_pays'] ?? null);
        }

        return [
            'raison_sociale' => (string) $data['raison_sociale'],
            'raison_sociale_saisie' => trim($raisonSocialeSaisie),
            'telephone' => $telephone,
            'identifiant_fiscal' => isset($data['identifiant_fiscal']) ? trim((string) $data['identifiant_fiscal']) : null,
        ];
    }
}
