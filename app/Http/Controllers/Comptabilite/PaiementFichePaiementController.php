<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Services\AuditLogService;
use App\Services\Commission\MoteurCommissionResolver;
use App\Services\CommissionEnveloppePartAllocationService;
use App\Services\PeriodePayabilityChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaiementFichePaiementController extends Controller
{
    public function store(Request $request, PaiementFiche $fiche): RedirectResponse
    {
        $this->authorize('payer', $fiche);

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $restant = $fiche->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1', 'max:'.$restant],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            // Étiquette libre du wallet Mobile Money (ex: "orange", "mtn", "djomy") —
            // jamais un enum fermé : chaque organisation nomme ses propres wallets.
            // Ignoré si mode_paiement != mobile_money.
            'moyen_paiement_detail' => ['nullable', 'string', 'max:30'],
            'date_paiement' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $fiche->organization_id,
            'site_id' => $fiche->site_id,
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'moyen_paiement_detail' => $data['mode_paiement'] === ModePaiement::MOBILE_MONEY->value
                ? ($data['moyen_paiement_detail'] ?? null)
                : null,
            'date_paiement' => $data['date_paiement'],
            'note' => $data['note'] ?? null,
        ]);

        // V2 uniquement : reporte ce paiement sur les CommissionEnveloppePart
        // sous-jacentes de la fiche, pour que Commission vente/propriétaire
        // restent synchronisées avec ce paiement (cf. décision AMOA — une
        // seule chaîne de paiement, jamais deux circuits pour V2). Sans effet
        // pour une fiche Legacy (comportement 100% inchangé).
        if (MoteurCommissionResolver::estV2($fiche->organization_id)) {
            CommissionEnveloppePartAllocationService::allouer($fiche, $paiement);
        }

        $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAID, auth()->user(), null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'description' => "Paiement de {$montantFmt} GNF enregistré pour {$fiche->beneficiaire_nom}",
        ]);

        return back()->with('success', 'Paiement enregistré avec succès.');
    }

    public function destroy(PaiementFichePaiement $paiement): RedirectResponse
    {
        $fiche = $paiement->fiche;
        $this->authorize('payer', $fiche);

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $montantFmt = number_format((float) $paiement->montant, 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($fiche, AuditEvent::PAYMENT_CANCELLED, auth()->user(), null, null, [
            'module' => 'fiches_paiement',
            'site_id' => $fiche->site_id,
            'montant' => (float) $paiement->montant,
            'description' => "Paiement de {$montantFmt} GNF annulé pour {$fiche->beneficiaire_nom}",
        ]);

        if (MoteurCommissionResolver::estV2($fiche->organization_id)) {
            CommissionEnveloppePartAllocationService::desallouer($paiement);
        }

        $paiement->delete();

        return back()->with('success', 'Paiement supprimé.');
    }
}
