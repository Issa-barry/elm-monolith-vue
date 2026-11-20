<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Services\AuditLogService;
use App\Services\Commission\PaiementFicheService;
use App\Services\CommissionEnveloppePartAllocationService;
use App\Services\PeriodePayabilityChecker;
use App\Services\Tresorerie\DecaissementFicheResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaiementFichePaiementController extends Controller
{
    public const MESSAGE_SALARIE = 'Les salaires se paient depuis Comptabilité > Paiement salaire.';

    public function store(Request $request, PaiementFiche $fiche): RedirectResponse
    {
        $this->authorize('payer', $fiche);

        // Un salaire a un seul circuit de paiement : la Paie (PaieLigne/PaiePaiement, comptabilisée
        // par PaieComptabilisationService). La fiche salarié est construite sur la même PaieLigne
        // sans qu'aucun des deux circuits ne voie l'autre : la payer ici permettrait de payer deux
        // fois le même salaire, sans écriture comptable (ADR 0009).
        if ($fiche->beneficiaire_type === 'salarie') {
            throw ValidationException::withMessages(['fiche' => self::MESSAGE_SALARIE]);
        }

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $restant = $fiche->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1', 'max:'.$restant],
            'mode_paiement' => ['required', 'in:'.implode(',', array_column(ModePaiement::cases(), 'value'))],
            // Support d'où sort l'argent (ADR 0009) — inutile en espèces : la caisse dédiée du payeur
            // est résolue côté serveur, jamais choisie par l'écran.
            'compte_tresorerie_id' => ['nullable', 'string', 'required_unless:mode_paiement,'.ModePaiement::ESPECES->value],
            'reference_paiement' => ['nullable', 'string', 'max:190'],
            'date_paiement' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ], [
            'compte_tresorerie_id.required_unless' => "Choisissez le compte d'où sort ce paiement.",
        ]);

        $decaissement = app(DecaissementFicheResolver::class);
        $user = $request->user();
        $support = $decaissement->supportPour($fiche, $user, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);

        if ($decaissement->referenceRequise($fiche, $data['mode_paiement'], $support->id) && blank($data['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages([
                'reference_paiement' => 'La référence du paiement est obligatoire pour ce mode de paiement.',
            ]);
        }

        $paiements = app(PaiementFicheService::class);

        try {
            $paiement = DB::transaction(fn () => $paiements->enregistrer($fiche, $support, $data));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non enregistré : {$e->getMessage()}"]);
        }

        $paiements->apresEnregistrement($fiche, $paiement, $user);

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

        CommissionEnveloppePartAllocationService::desallouer($paiement);

        $paiement->delete();

        return back()->with('success', 'Paiement supprimé.');
    }
}
