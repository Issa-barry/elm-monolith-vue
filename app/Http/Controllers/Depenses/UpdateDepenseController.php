<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDepenseRequest;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class UpdateDepenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function __invoke(UpdateDepenseRequest $request, Depense $depense): RedirectResponse
    {
        $this->authorize('update', $depense);

        $user = auth()->user();
        $orgId = $user->organization_id;

        if (! $user->isAdmin()) {
            $allowedSiteIds = $user->sites()->pluck('sites.id')->all();
            abort_unless(
                in_array($request->site_id, $allowedSiteIds, true),
                403,
                'Vous ne pouvez pas choisir ce site.'
            );
        }

        $type = DepenseType::where('organization_id', $orgId)->findOrFail($request->depense_type_id);

        $submittableStatuts = [StatutDepense::BROUILLON, StatutDepense::REJETE, StatutDepense::ANNULE];
        $shouldSubmit = $request->input('statut') === 'soumis'
            && in_array($depense->statut, $submittableStatuts, true);

        $fields = ['depense_type_id', 'beneficiaire_type', 'beneficiaire_id', 'site_id', 'montant', 'date_depense', 'commentaire'];
        $before = $depense->only($fields);

        $depense->update([
            'depense_type_id' => $request->depense_type_id,
            'beneficiaire_type' => $type->categorie->needsBeneficiaire() ? $type->categorie->value : null,
            'beneficiaire_id' => $type->categorie->needsBeneficiaire() ? $request->beneficiaire_id : null,
            'site_id' => $request->site_id,
            'montant' => $request->montant,
            'date_depense' => $request->date_depense,
            'commentaire' => $request->commentaire,
        ]);

        [$oldDiff, $newDiff] = $this->audit->diffFields($before, $depense->fresh()->only($fields), $fields);
        $this->audit->record($depense, AuditEvent::UPDATED, $user, $oldDiff, $newDiff, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => 'Dépense modifiée',
        ]);

        if ($shouldSubmit) {
            $depense->update(['statut' => StatutDepense::SOUMIS]);
            $this->audit->record($depense, AuditEvent::SUBMITTED, $user, null, null, [
                'module' => 'depenses',
                'site_id' => $depense->site_id,
                'description' => 'Dépense soumise pour validation',
            ]);

            return redirect()->route('depenses.show', $depense)->with('success', 'Dépense soumise pour validation.');
        }

        return redirect()->route('depenses.show', $depense)->with('success', 'Dépense mise à jour.');
    }
}
