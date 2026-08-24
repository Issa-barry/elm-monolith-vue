<?php

namespace App\Http\Controllers;

use App\Enums\CategorieDepense;
use App\Http\Requests\StoreDepenseTypeRequest;
use App\Http\Requests\UpdateDepenseTypeRequest;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Services\DepenseTypes\DepenseTypeListExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Types de dépense — module Dépenses (pas Paramètres, cf. décision produit
 * 2026-08-24 : la classification métier des dépenses est une donnée du
 * module Dépenses, pas un réglage d'organisation). Les droits de validation
 * restent dans Settings\DepenseParametrageController.
 */
class DepenseTypeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', DepenseType::class);

        return Inertia::render('Depenses/Types/Index', [
            'types' => $this->typesPourOrganisation(auth()->user()->organization_id),
            'categories' => CategorieDepense::options(),
        ]);
    }

    public function store(StoreDepenseTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', DepenseType::class);

        $orgId = auth()->user()->organization_id;

        try {
            DepenseType::create([
                ...$request->validated(),
                'organization_id' => $orgId,
                'code' => $this->generateCode($request->libelle, $orgId),
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'libelle' => 'Un type de dépense avec un nom équivalent existe déjà (ou a été supprimé) dans cette organisation.',
            ]);
        }

        return back()->with('success', 'Type de dépense créé.');
    }

    public function update(UpdateDepenseTypeRequest $request, DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('update', $depense_type);

        $depense_type->update($request->validated());

        return back()->with('success', 'Type de dépense mis à jour.');
    }

    public function toggle(Request $request, DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('update', $depense_type);

        $depense_type->update(['is_active' => ! $depense_type->is_active]);

        $label = $depense_type->is_active ? 'activé' : 'désactivé';

        return back()->with('success', "Type « {$depense_type->libelle} » {$label}.");
    }

    public function destroy(DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('delete', $depense_type);

        if ($depense_type->depenses()->exists()) {
            return back()->withErrors(['delete' => 'Ce type est utilisé dans des dépenses. Désactivez-le plutôt que de le supprimer.']);
        }

        $depense_type->delete();

        return back()->with('success', 'Type de dépense supprimé.');
    }

    public function exportExcel(Request $request)
    {
        // Export aligné sur les mêmes permissions que la création/l'import (cf.
        // brief : « créer, importer ou exporter » relèvent des droits de gestion,
        // pas du simple droit de consultation) — pas de nouvelle permission.
        $this->authorize('create', DepenseType::class);

        $types = $this->filteredTypes($request);
        $filename = 'types-depense-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new DepenseTypeListExport($types), $filename);
    }

    public function exportPdf(Request $request)
    {
        $this->authorize('create', DepenseType::class);

        $types = $this->filteredTypes($request);
        $org = Organization::find(auth()->user()->organization_id);

        $pdf = Pdf::loadView('pdf.depense_types', [
            'types' => $types,
            'org_nom' => $org?->nom ?? '',
            'filtres' => $this->filtresLabel($request),
            'generated_at' => now()->format('d/m/Y à H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('types-depense-'.now()->format('Y-m-d').'.pdf');
    }

    /** @return array<int, array<string, mixed>> */
    private function typesPourOrganisation(string $orgId): array
    {
        return DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get()
            ->map(fn (DepenseType $t) => [
                'id' => $t->id,
                'libelle' => $t->libelle,
                'description' => $t->description,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'commentaire_obligatoire' => $t->commentaire_obligatoire,
                'justificatif_obligatoire' => $t->justificatif_obligatoire,
                'type_paie' => $t->type_paie,
                'is_active' => $t->is_active,
                'depenses_count' => $t->depenses()->count(),
            ])
            ->all();
    }

    /**
     * Ré-applique côté serveur les mêmes filtres que la liste (concerné,
     * statut) pour que l'export Excel/PDF reflète toujours l'ensemble des
     * lignes filtrées, pas seulement la page actuellement affichée côté
     * client (celle-ci n'est jamais paginée côté serveur pour ce module).
     */
    private function filteredTypes(Request $request): Collection
    {
        $orgId = auth()->user()->organization_id;
        $categorie = (string) $request->input('categorie', '');
        $statut = (string) $request->input('statut', '');

        return DepenseType::where('organization_id', $orgId)
            ->when($categorie !== '', fn ($q) => $q->where('categorie', $categorie))
            ->when($statut === 'actif', fn ($q) => $q->where('is_active', true))
            ->when($statut === 'inactif', fn ($q) => $q->where('is_active', false))
            ->ordered()
            ->get();
    }

    private function filtresLabel(Request $request): string
    {
        $parts = [];

        $categorie = (string) $request->input('categorie', '');
        if ($categorie !== '') {
            $cat = CategorieDepense::tryFrom($categorie);
            $parts[] = 'Concerné : '.($cat?->label() ?? $categorie);
        }

        $statut = (string) $request->input('statut', '');
        if ($statut === 'actif') {
            $parts[] = 'Statut : Actif';
        } elseif ($statut === 'inactif') {
            $parts[] = 'Statut : Inactif';
        }

        return $parts === [] ? 'Aucun filtre appliqué' : implode(' · ', $parts);
    }

    private function generateCode(string $libelle, string $orgId, ?string $excludeId = null): string
    {
        $base = Str::slug($libelle, '_');
        $code = $base;
        $i = 2;

        while (
            DepenseType::withTrashed()
                ->where('organization_id', $orgId)
                ->where('code', $code)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
