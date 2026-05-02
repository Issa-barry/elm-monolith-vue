<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreDepenseTypeRequest;
use App\Http\Requests\Settings\UpdateDepenseTypeRequest;
use App\Models\DepenseType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'code' => $t->code,
                'libelle' => $t->libelle,
                'description' => $t->description,
                'requires_vehicle' => $t->requires_vehicle,
                'requires_comment' => $t->requires_comment,
                'is_active' => $t->is_active,
                'sort_order' => $t->sort_order,
            ]);

        return Inertia::render('settings/DepenseTypes/Index', [
            'types' => $types,
        ]);
    }

    public function store(StoreDepenseTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', DepenseType::class);

        DepenseType::create([
            ...$request->validated(),
            'organization_id' => auth()->user()->organization_id,
            'code' => strtolower(trim($request->code)),
        ]);

        return back()->with('success', 'Type de dépense créé.');
    }

    public function update(UpdateDepenseTypeRequest $request, DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('update', $depense_type);

        $depense_type->update([
            ...$request->validated(),
            'code' => strtolower(trim($request->code)),
        ]);

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
            return back()->withErrors(['delete' => 'Ce type est utilisé dans des dépenses. Désactivez-le plutôt.']);
        }

        $depense_type->delete();

        return back()->with('success', 'Type de dépense supprimé.');
    }
}
