<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CategorieDepense;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreDepenseTypeRequest;
use App\Http\Requests\Settings\UpdateDepenseTypeRequest;
use App\Models\DepenseType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DepenseTypeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', DepenseType::class);

        $orgId = auth()->user()->organization_id;

        $types = DepenseType::where('organization_id', $orgId)
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
            ]);

        return Inertia::render('settings/DepenseTypes/Index', [
            'types' => $types,
            'categories' => CategorieDepense::options(),
        ]);
    }

    public function store(StoreDepenseTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', DepenseType::class);

        $orgId = auth()->user()->organization_id;

        DepenseType::create([
            ...$request->validated(),
            'organization_id' => $orgId,
            'code' => $this->generateCode($request->libelle, $orgId),
        ]);

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

    private function generateCode(string $libelle, string $orgId, ?string $excludeId = null): string
    {
        $base = Str::slug($libelle, '_');
        $code = $base;
        $i = 2;

        while (
            DepenseType::where('organization_id', $orgId)
                ->where('code', $code)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->whereNull('deleted_at')
                ->exists()
        ) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
