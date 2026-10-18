<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDepenseRequest;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Services\AuditLogService;
use App\Services\Depenses\CommandeCarburantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDepenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CommandeCarburantService $commandeCarburant,
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

        $fields = ['depense_type_id', 'beneficiaire_type', 'beneficiaire_id', 'commande_vente_id', 'transfert_logistique_id', 'motif_sans_commande', 'site_id', 'montant', 'date_depense', 'commentaire'];
        $before = $depense->only($fields);

        $rattachement = $request->rattachementCommandeAttributs();

        DB::transaction(function () use ($request, $depense, $type, $rattachement) {
            // Unicité de la commande (ADR 0031, DEPCARB-004), reposée sous verrou.
            $erreur = $this->commandeCarburant->verrouillerUnicite($rattachement['commande_vente_id'], $rattachement['transfert_logistique_id'], $depense->id);
            if ($erreur !== null) {
                throw ValidationException::withMessages(['commande' => ucfirst($erreur)]);
            }

            $depense->update([
                'depense_type_id' => $request->depense_type_id,
                'beneficiaire_type' => $type->categorie->needsBeneficiaire() ? $type->categorie->value : null,
                'beneficiaire_id' => $type->categorie->needsBeneficiaire() ? $request->beneficiaire_id : null,
                ...$rattachement,
                'site_id' => $request->site_id,
                'montant' => $request->montant,
                'date_depense' => $request->date_depense,
                'commentaire' => $request->commentaire,
            ]);
        });

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
