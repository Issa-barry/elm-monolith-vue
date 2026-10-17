<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class SoumettreDepenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function __invoke(Depense $depense): RedirectResponse
    {
        $this->authorize('view', $depense);

        $submittable = [StatutDepense::BROUILLON, StatutDepense::REJETE, StatutDepense::ANNULE];
        if (! in_array($depense->statut, $submittable, true)) {
            return back()->withErrors(['statut' => 'Cette dépense ne peut pas être soumise.']);
        }

        // ADR 0031 : une dépense dont le type exige une commande (brouillon antérieur à la règle)
        // ne part en validation qu'une fois rattachée, ou déclarée sans commande avec son motif.
        if ($depense->depenseType?->commande_obligatoire
            && ! $depense->commande_vente_id
            && ! $depense->transfert_logistique_id
            && ! $depense->motif_sans_commande) {
            return back()->withErrors(['commande' => 'Rattachez cette dépense à une commande (ou déclarez-la sans commande avec son motif) avant de la soumettre.']);
        }

        $depense->update(['statut' => StatutDepense::SOUMIS]);
        $this->audit->record($depense, AuditEvent::SUBMITTED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => 'Dépense soumise pour validation',
        ]);

        return back()->with('success', 'Dépense soumise pour validation.');
    }
}
