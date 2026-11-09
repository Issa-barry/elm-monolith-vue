<?php

namespace App\Http\Controllers;

use App\Enums\PrestataireType;
use App\Models\Prestataire;
use App\Services\Contacts\QualificationsPrestataire;
use App\Support\Contacts\SuppressionFonction;
use App\Traits\PhoneHandlerTrait;
use App\Traits\ResolutionIdentiteTiersTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PrestataireController extends Controller
{
    use PhoneHandlerTrait, ResolutionIdentiteTiersTrait;

    public function index(): Response
    {
        $this->authorize('viewAny', Prestataire::class);

        $prestataires = Prestataire::with(['personne', 'entrepriseTierce', 'sousCategories'])
            ->where('organization_id', auth()->user()->organization_id)
            ->get();
        $nonSupprimables = array_flip(SuppressionFonction::idsReferences(Prestataire::class, $prestataires->pluck('id')->all()));

        $prestataires = $prestataires
            ->sortBy('nom_complet')
            ->values()
            ->map(fn (Prestataire $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'nom_complet' => $p->nom_complet,
                'nom' => $p->nom,
                'prenom' => $p->prenom,
                'raison_sociale' => $p->raison_sociale,
                'email' => $p->email,
                'phone' => $p->phone,
                'code_phone_pays' => $p->code_phone_pays,
                'ville' => $p->ville,
                'type' => $p->type?->value,
                'type_label' => $p->type_label,
                // Lot 6b : qualifications (badges) ; repli sur l'ancien type tant qu'aucune n'est enregistrée.
                'qualifications' => $p->sousCategories->isNotEmpty()
                    ? $p->sousCategories->sortBy([['ordre', 'asc'], ['libelle', 'asc']])->map(fn ($s) => ['code' => $s->code, 'libelle' => $s->libelle])->values()->all()
                    : ($p->type ? [['code' => $p->type->value, 'libelle' => $p->type->label()]] : []),
                'is_active' => $p->is_active,
                'supprimable' => ! isset($nonSupprimables[$p->id]),
            ]);

        return Inertia::render('Prestataires/Index', [
            'prestataires' => $prestataires,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Prestataire::class);

        return Inertia::render('Prestataires/Create', [
            // Lot 6b : qualifications actives du référentiel (valeur = id).
            'types' => QualificationsPrestataire::options(auth()->user()->organization_id),
        ]);
    }

    public function store(Request $request, QualificationsPrestataire $qualifications): RedirectResponse
    {
        $this->authorize('create', Prestataire::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $data = $request->validate($this->validationRules(), $this->validationMessages());

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength(array_merge($data, ['telephone' => $data['phone'] ?? null]));

        $data = $this->normalizeData($data);

        $ids = $this->qualificationsDemandees($qualifications, $orgId, $data);

        DB::transaction(function () use ($orgId, $data, $ids, $qualifications, $request) {
            $identite = $this->resoudreIdentite($orgId, $data);

            $prestataire = Prestataire::create([
                'organization_id' => $orgId,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                ...$identite,
            ]);

            // Lot 6b (ADR 0060) : qualifications multiples ; `type` en est la copie de compatibilité.
            $qualifications->synchroniser($request->user(), $prestataire, $ids);
        });

        return redirect()->route('prestataires.index')
            ->with('success', 'Prestataire créé avec succès.');
    }

    public function edit(Prestataire $prestataire): Response
    {
        $this->authorize('update', $prestataire);

        [$phone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $prestataire->phone,
            $prestataire->code_phone_pays,
            $prestataire->code_pays,
            $prestataire->pays,
        );

        return Inertia::render('Prestataires/Edit', [
            'prestataire' => [
                'id' => $prestataire->id,
                'reference' => $prestataire->reference,
                'nom' => $prestataire->nom,
                'prenom' => $prestataire->prenom,
                'raison_sociale' => $prestataire->raison_sociale,
                'email' => $prestataire->email,
                'phone' => $phone,
                'code_phone_pays' => $codePhonePays,
                'code_pays' => $codePays,
                'pays' => $pays,
                'ville' => $prestataire->ville,
                'adresse' => $prestataire->adresse,
                'type' => $prestataire->type?->value,
                'qualification_ids' => $portees = $this->qualificationsAffichees($prestataire),
                'notes' => $prestataire->notes,
                'is_active' => $prestataire->is_active,
            ],
            'types' => QualificationsPrestataire::options($prestataire->organization_id, $portees),
        ]);
    }

    public function update(Request $request, Prestataire $prestataire, QualificationsPrestataire $qualifications): RedirectResponse
    {
        $this->authorize('update', $prestataire);

        $data = $request->validate($this->validationRules(), $this->validationMessages());

        $data = $this->resolveCountryData($data);
        $this->validateLocalPhoneLength(array_merge($data, ['telephone' => $data['phone'] ?? null]));

        $data = $this->normalizeData($data);

        $ids = $this->qualificationsDemandees($qualifications, $prestataire->organization_id, $data);

        DB::transaction(function () use ($prestataire, $data, $ids, $qualifications, $request) {
            $identite = $this->resoudreIdentite($prestataire->organization_id, $data, $prestataire);

            $prestataire->update([
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? $prestataire->is_active,
                ...$identite,
            ]);

            $qualifications->synchroniser($request->user(), $prestataire, $ids);
        });

        return redirect()->route('prestataires.edit', $prestataire)
            ->with('success', 'Prestataire mis à jour avec succès.');
    }

    /**
     * Qualifications demandées : `qualification_ids`, sinon l'ancien `type` ; au moins une (Q1).
     *
     * @return list<string>
     */
    private function qualificationsDemandees(QualificationsPrestataire $qualifications, string $organizationId, array $data): array
    {
        $ids = $qualifications->idsDemandes(
            $organizationId,
            array_key_exists('qualification_ids', $data) ? array_values((array) ($data['qualification_ids'] ?? [])) : null,
            $data['type'] ?? null,
        );

        if ($ids === null || $ids === []) {
            throw ValidationException::withMessages(['qualification_ids' => 'Choisissez au moins une qualification.']);
        }

        return $ids;
    }

    /**
     * Qualifications présentées par le formulaire : celles enregistrées, sinon celle de l'ancien
     * type (prestataire pas encore repris), pour que l'enregistrement les écrive.
     *
     * @return list<string>
     */
    private function qualificationsAffichees(Prestataire $prestataire): array
    {
        $portees = QualificationsPrestataire::portees($prestataire);
        if ($portees !== [] || $prestataire->type === null) {
            return $portees;
        }

        return app(QualificationsPrestataire::class)->idsDemandes($prestataire->organization_id, null, $prestataire->type->value) ?? [];
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
            // Lot 6b : qualifications multiples ; l'ancien champ `type` (un code) reste accepté.
            'qualification_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'qualification_ids.*' => ['required', 'string', 'max:26'],
            // Q1 : au moins une qualification ; sans `qualification_ids`, l'ancien `type` est exigé (même
            // contrat qu'avant pour les anciens écrans et clients).
            'type' => ['nullable', 'required_without:qualification_ids', Rule::enum(PrestataireType::class)],
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
            'type.enum' => 'Qualification inconnue.',
            'type.required_without' => 'Choisissez au moins une qualification.',
        ];
    }

    public function destroy(Prestataire $prestataire): RedirectResponse
    {
        $this->authorize('delete', $prestataire);

        // D17 (ADR 0060) : un prestataire référencé par une opération se désactive, il ne se supprime plus.
        if (! SuppressionFonction::estSupprimable($prestataire)) {
            return back()->with('error', SuppressionFonction::message($prestataire));
        }

        $prestataire->delete();

        return redirect()->route('prestataires.index')
            ->with('success', 'Prestataire supprimé.');
    }
}
