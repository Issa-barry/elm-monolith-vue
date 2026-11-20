<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Services\Commission\PaiementFicheService;
use App\Services\PeriodePayabilityChecker;
use App\Services\Tresorerie\DecaissementFicheResolver;
use App\Support\Paiements\OperationPaiement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaiementFichePaiementController extends Controller
{
    public const MESSAGE_SALARIE = 'Les salaires se paient depuis Comptabilité > Paiement salaire.';

    public const MESSAGE_SUCCES = 'Paiement enregistré avec succès.';

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

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1'],
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

        // Idempotence (ADR 0069) : un double envoi ou une nouvelle tentative de la même opération
        // restitue le paiement d'origine, avant tout contrôle qui dépend de l'état actuel de la fiche
        // (reste à payer, période) — sinon le rejeu d'un paiement réussi serait signalé en erreur.
        $operation = OperationPaiement::depuisRequete($request, [
            'type' => 'paiement_fiche',
            'fiche_id' => $fiche->id,
            'montant' => OperationPaiement::montant($data['montant']),
            'mode_paiement' => $data['mode_paiement'],
            'compte_tresorerie_id' => $data['compte_tresorerie_id'] ?? null,
            'reference_paiement' => $data['reference_paiement'] ?? null,
            'date_paiement' => $data['date_paiement'],
            'note' => $data['note'] ?? null,
        ]);
        $paiements = app(PaiementFicheService::class);
        if ($paiements->paiementsDeLOperation($fiche->organization_id, $operation)->isNotEmpty()) {
            return back()->with('success', self::MESSAGE_SUCCES);
        }

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $request->validate(['montant' => ['numeric', 'max:'.$fiche->montant_restant]]);

        $decaissement = app(DecaissementFicheResolver::class);
        $user = $request->user();
        $support = $decaissement->supportPour($fiche, $user, $data['mode_paiement'], $data['compte_tresorerie_id'] ?? null);

        if ($decaissement->referenceRequise($fiche, $data['mode_paiement'], $support->id) && blank($data['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages([
                'reference_paiement' => 'La référence du paiement est obligatoire pour ce mode de paiement.',
            ]);
        }

        try {
            $paiement = DB::transaction(fn () => $paiements->enregistrer($fiche, $support, $data, $operation));
        } catch (UniqueConstraintViolationException $e) {
            // Dernier filet (contrainte pfp_fiche_operation_unique) : l'envoi jumeau est passé.
            if ($paiements->paiementsDeLOperation($fiche->organization_id, $operation)->isEmpty()) {
                throw $e;
            }

            return back()->with('success', self::MESSAGE_SUCCES);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non enregistré : {$e->getMessage()}"]);
        }

        // Rejeu sous verrou : ni notification ni historique une seconde fois.
        if ($paiement->wasRecentlyCreated) {
            $paiements->apresEnregistrement($fiche, $paiement, $user);
        }

        return back()->with('success', self::MESSAGE_SUCCES);
    }

    public function destroy(PaiementFichePaiement $paiement, PaiementFicheService $paiements): RedirectResponse
    {
        $fiche = $paiement->fiche;
        $this->authorize('payer', $fiche);

        try {
            PeriodePayabilityChecker::assertPeriodePayable($fiche->periode);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // Tout ou rien : sans contrepassation de la pièce, ni suppression, ni historique, ni
        // désallocation. Un second envoi simultané trouve le paiement déjà supprimé et n'écrit rien.
        try {
            DB::transaction(fn () => $paiements->supprimer($paiement, auth()->user()));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non supprimé : {$e->getMessage()}"]);
        }

        return back()->with('success', 'Paiement supprimé.');
    }
}
