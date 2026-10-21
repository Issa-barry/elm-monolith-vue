<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use App\Support\Documents\IdentiteOrganisation;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration de l'organisation courante (identité affichée dans la
 * sidebar/header : nom, logo ; pied de page officiel des factures et bons, ADR 0033).
 * Portée strictement limitée à l'organisation
 * de l'utilisateur connecté — jamais de sélection d'une autre organisation.
 */
class OrganisationController extends Controller
{
    public function edit(): Response
    {
        $this->authorizeAdmin();

        $org = auth()->user()->organization;

        return Inertia::render('settings/Organisation', [
            'organisation' => [
                'name' => $org->name,
                'slug' => $org->slug,
                'code' => $org->code,
                'siret' => $org->siret,
                'logo_url' => $org->logo_url,
                'pied_page_documents' => $org->pied_page_documents,
            ],
            'login_url' => route('login.org', $org->code),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $org = auth()->user()->organization;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'min:2', 'max:20', 'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('organizations', 'code')->ignore($org->id),
            ],
            'siret' => ['nullable', 'string', 'max:14'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_logo' => ['nullable', 'boolean'],
            'pied_page_documents' => ['nullable', 'string', 'max:1000', function (string $attribut, mixed $valeur, Closure $echec) {
                $lignes = IdentiteOrganisation::lignesPied($valeur);
                if (count($lignes) > IdentiteOrganisation::PIED_MAX_LIGNES) {
                    $echec('Le pied de page ne peut pas dépasser '.IdentiteOrganisation::PIED_MAX_LIGNES.' lignes.');
                } else {
                    foreach ($lignes as $i => $ligne) {
                        if (mb_strlen($ligne) > IdentiteOrganisation::PIED_MAX_CARACTERES_LIGNE) {
                            $echec('La ligne '.($i + 1).' compte '.mb_strlen($ligne).' caractères (maximum '.IdentiteOrganisation::PIED_MAX_CARACTERES_LIGNE.') : appuyez sur Entrée pour passer à la ligne entre chaque information.');

                            return;
                        }
                    }
                }
            }],
        ], [
            'name.required' => "Le nom de l'organisation est obligatoire.",
            'code.required' => 'Le code organisation est obligatoire.',
            'code.regex' => 'Le code ne peut contenir que des lettres et des chiffres.',
            'code.unique' => 'Ce code est déjà utilisé par une autre organisation.',
            'logo.image' => 'Le fichier doit être une image.',
            'logo.mimes' => 'Le logo doit être au format jpg, jpeg, png ou webp.',
            'logo.max' => 'Le logo ne peut pas dépasser 3 Mo.',
        ]);

        $data['code'] = mb_strtoupper($data['code']);
        if (array_key_exists('pied_page_documents', $data)) {
            $data['pied_page_documents'] = implode("\n", IdentiteOrganisation::lignesPied($data['pied_page_documents'])) ?: null;
        }

        $imageService = new ImageService;

        if ($request->hasFile('logo')) {
            $imageService->delete($org->logo_path);
            $data['logo_path'] = $imageService->storeAsWebp($request->file('logo'), 'organizations');
        } elseif (! empty($data['remove_logo'])) {
            $imageService->delete($org->logo_path);
            $data['logo_path'] = null;
        }

        unset($data['logo'], $data['remove_logo']);
        $org->update($data);

        return back()->with('success', 'Organisation mise à jour.');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403, "Vous n'êtes pas autorisé à administrer l'organisation.");
        abort_unless(auth()->user()->organization_id, 403, "Votre compte n'est associé à aucune organisation.");
    }
}
