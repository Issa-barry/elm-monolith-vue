<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FournisseurController extends Controller
{
    use PhoneHandlerTrait;

    public function index(): Response
    {
        $this->authorize('viewAny', Fournisseur::class);

        $fournisseurs = Fournisseur::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Fournisseur $f) => [
                'id' => $f->id,
                'reference' => $f->reference,
                'nom_complet' => $f->nom_complet,
                'nom' => $f->nom,
                'prenom' => $f->prenom,
                'raison_sociale' => $f->raison_sociale,
                'email' => $f->email,
                'phone' => $f->phone,
                'code_phone_pays' => $f->code_phone_pays,
                'ville' => $f->ville,
                'is_active' => $f->is_active,
            ]);

        return Inertia::render('Fournisseurs/Index', [
            'fournisseurs' => $fournisseurs,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Fournisseur::class);

        return Inertia::render('Fournisseurs/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Fournisseur::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $data = $request->validate($this->validationRules(), $this->validationMessages());

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength(array_merge($data, ['telephone' => $data['phone'] ?? null]));

        $data = $this->normalizeData($data);

        Fournisseur::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('fournisseurs.index')
            ->with('success', 'Fournisseur créé avec succès.');
    }

    /**
     * Création rapide (formulaire minimal) — utilisée par FournisseurSelect.vue depuis le
     * formulaire Produit, sans quitter la page. Mêmes normalisations (téléphone/pays) que
     * store(), mais un formulaire volontairement plus léger : pas de nom/prénom séparés,
     * ville et adresse facultatives (la fiche complète — email, notes... — reste éditable
     * ensuite depuis Fournisseurs > Modifier).
     */
    public function storeRapide(Request $request): RedirectResponse
    {
        $this->authorize('create', Fournisseur::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $data = $request->validate([
            'raison_sociale' => 'required|string|max:255',
            'phone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string',
        ], [
            'raison_sociale.required' => 'Le nom du fournisseur est obligatoire.',
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
        ]);

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength(array_merge($data, ['telephone' => $data['phone']]));

        // Détection de doublon sur le téléphone NORMALISÉ (pas la saisie brute) — la contrainte
        // unique(['organization_id','phone']) protège déjà la base, ce contrôle sert uniquement
        // à retourner un message clair plutôt qu'une erreur 500 en cas de collision.
        $telephoneNormalise = Fournisseur::normalizePhoneE164($data['phone'], $data['code_phone_pays']);
        $doublon = Fournisseur::where('organization_id', $orgId)
            ->where('phone', $telephoneNormalise)
            ->first();
        if ($doublon) {
            throw ValidationException::withMessages([
                'phone' => "Un fournisseur avec ce numéro existe déjà : {$doublon->nom_complet}.",
            ]);
        }

        $data = $this->normalizeData($data);

        $fournisseur = Fournisseur::create([
            ...$data,
            'organization_id' => $orgId,
        ]);

        // Permet à FournisseurSelect.vue (création rapide depuis le formulaire Produit) de
        // sélectionner automatiquement le fournisseur tout juste créé.
        return back()
            ->with('success', 'Fournisseur créé avec succès.')
            ->with('created_fournisseur_id', $fournisseur->id);
    }

    public function edit(Fournisseur $fournisseur): Response
    {
        $this->authorize('update', $fournisseur);

        [$phone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $fournisseur->phone,
            $fournisseur->code_phone_pays,
            $fournisseur->code_pays,
            $fournisseur->pays,
        );

        return Inertia::render('Fournisseurs/Edit', [
            'fournisseur' => [
                'id' => $fournisseur->id,
                'reference' => $fournisseur->reference,
                'nom' => $fournisseur->nom,
                'prenom' => $fournisseur->prenom,
                'raison_sociale' => $fournisseur->raison_sociale,
                'email' => $fournisseur->email,
                'phone' => $phone,
                'code_phone_pays' => $codePhonePays,
                'code_pays' => $codePays,
                'pays' => $pays,
                'ville' => $fournisseur->ville,
                'adresse' => $fournisseur->adresse,
                'notes' => $fournisseur->notes,
                'is_active' => $fournisseur->is_active,
            ],
        ]);
    }

    public function update(Request $request, Fournisseur $fournisseur): RedirectResponse
    {
        $this->authorize('update', $fournisseur);

        $data = $request->validate($this->validationRules(), $this->validationMessages());

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength(array_merge($data, ['telephone' => $data['phone'] ?? null]));

        $data = $this->normalizeData($data);

        $fournisseur->update($data);

        return redirect()->route('fournisseurs.edit', $fournisseur)
            ->with('success', 'Fournisseur mis à jour avec succès.');
    }

    private function validationRules(): array
    {
        return [
            'nom' => 'nullable|string|max:255|required_without:raison_sociale',
            'prenom' => 'nullable|string|max:255|required_without:raison_sociale',
            'raison_sociale' => 'nullable|string|max:255|required_without_all:nom,prenom',
            'email' => 'nullable|email:rfc,dns|max:255',
            'phone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['required', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'required|string|max:100',
            'adresse' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }

    private function validationMessages(): array
    {
        return [
            'raison_sociale.required_without_all' => 'La raison sociale est obligatoire si le prénom et le nom sont absents.',
            'nom.required_without' => 'Le nom est obligatoire si la raison sociale est absente.',
            'prenom.required_without' => 'Le prénom est obligatoire si la raison sociale est absente.',
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
            'ville.required' => 'La ville est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
        ];
    }

    public function destroy(Fournisseur $fournisseur): RedirectResponse
    {
        $this->authorize('delete', $fournisseur);
        $fournisseur->delete();

        return redirect()->route('fournisseurs.index')
            ->with('success', 'Fournisseur supprimé.');
    }
}
