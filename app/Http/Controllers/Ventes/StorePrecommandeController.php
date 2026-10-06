<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\EncaissementVente;
use App\Models\Parametre;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\Ventes\CommandeVenteCreationService;
use App\Services\Ventes\SaisieEncaissementVente;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Enregistrement d'une précommande (ADR 0019, docs/precommandes.md § 7.2) — tout ou rien, dans une
 * seule transaction : contrôle des impayés, disponibilité STRICTE, réservation, commande, facture
 * « Créée », acompte éventuel (encaissement `est_acompte`, crédité en avance client 419100) et son
 * écriture. Jamais d'argent encaissé sans réservation, jamais de réservation à découvert.
 */
class StorePrecommandeController extends Controller
{
    public function __construct(
        private readonly CommandeVenteCreationService $creation,
        private readonly CommandeVenteFormBuilder $formBuilder,
        private readonly SaisieEncaissementVente $saisie,
        private readonly AuditLogService $auditService,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('precommander', CommandeVente::class);

        $user = $request->user();
        $orgId = $user->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $userSite = $this->formBuilder->getUserSiteModel();
        if ($redirect = $this->formBuilder->redirectSiPrecommandeBloquee($orgId, $userSite->id)) {
            return $redirect;
        }

        $avecAcompte = (float) $request->input('acompte_montant', 0) > 0;

        $data = $request->validate([
            ...$this->formBuilder->commandeValidationRules(),
            // Client et véhicule bornés à l'organisation (CLAUDE.md § 11) : jamais une précommande
            // facturée au client ou livrée par le véhicule d'une autre organisation.
            'client_id' => ['required', Rule::exists('clients', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            // Le mode de remise n'est pas stocké : il est dérivé du véhicule (décision D4, comme le
            // mode Grossiste). La question posée à l'agent est seulement rendue cohérente ici.
            'mode_remise' => 'required|in:retrait,livraison',
            'vehicule_id' => ['nullable', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'), 'required_if:mode_remise,livraison', 'prohibited_if:mode_remise,retrait'],
            'date_remise_prevue' => 'required|date|after_or_equal:today',
            'acompte_montant' => 'nullable|numeric|min:0',
            ...($avecAcompte ? SaisieEncaissementVente::regles() : []),
        ], [
            ...$this->formBuilder->commandeValidationMessages(),
            ...SaisieEncaissementVente::messages(),
            'client_id.required' => 'Le client est obligatoire pour une précommande.',
            'mode_remise.required' => 'Choisissez le mode de remise : retrait sur site ou livraison.',
            'vehicule_id.required_if' => 'Une précommande en livraison exige un véhicule dès sa création.',
            'vehicule_id.prohibited_if' => 'Une précommande en retrait sur site ne prend pas de véhicule.',
            'date_remise_prevue.required' => 'La date prévue de retrait ou de livraison est obligatoire.',
            'date_remise_prevue.after_or_equal' => 'La date prévue ne peut pas être passée.',
            'acompte_montant.min' => 'L\'acompte ne peut pas être négatif.',
        ]);

        $acompte = round((float) ($data['acompte_montant'] ?? 0), 2);

        try {
            $commande = $this->creation->creer(
                $data,
                $orgId,
                $userSite,
                fn (CommandeVente $commande) => $this->enregistrer($commande, $acompte, $data, $request),
                attributs: [
                    'est_precommande' => true,
                    'date_remise_prevue' => $data['date_remise_prevue'],
                ],
                stockStrict: true,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Saisie concurrente de la même référence Mobile Money : tout est annulé, même message
            // qu'à l'encaissement d'une facture (ADR 0014) — jamais l'erreur SQL.
            if (! EncaissementVente::estDoublonReferenceMobileMoney($e)) {
                throw $e;
            }

            throw $this->saisie->referenceDejaUtilisee(
                EncaissementVente::factureUtilisantReferenceMobileMoney($orgId, $data['reference_paiement'] ?? null),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Précommande non enregistrée : {$e->getMessage()}"]);
        }

        return redirect()->route('ventes.show', $commande)->with(
            'success',
            $acompte > 0 ? 'Précommande enregistrée, acompte encaissé. Stock réservé.' : 'Précommande enregistrée sans acompte. Stock réservé.',
        );
    }

    /**
     * Suite de la création, dans la transaction de CommandeVenteCreationService : réservation
     * stricte + facture « Créée », puis acompte. Le minimum dépend du total, connu seulement ici
     * (prix figés) — un refus annule aussi la réservation déjà posée.
     *
     * @param  array<string, mixed>  $data
     */
    private function enregistrer(CommandeVente $commande, float $acompte, array $data, Request $request): void
    {
        CommandeVenteService::enregistrerPrecommande($commande);

        $total = (float) $commande->total_commande;
        $minimum = Parametre::precommandeAcompteMinimum($commande->organization_id, $total);

        if ($acompte < $minimum) {
            throw ValidationException::withMessages([
                'acompte_montant' => 'Acompte insuffisant : au moins '.self::gnf($minimum).' ('
                    .Parametre::getPrecommandeAcompteMinPct($commande->organization_id).' % du total de '.self::gnf($total).').',
            ]);
        }

        if ($acompte > $total) {
            throw ValidationException::withMessages([
                'acompte_montant' => 'L\'acompte ne peut pas dépasser le total de la précommande ('.self::gnf($total).').',
            ]);
        }

        CommandeVenteActiviteService::log($commande, 'precommande_enregistree', [
            'date_remise_prevue' => $data['date_remise_prevue'],
            'acompte' => $acompte,
        ]);

        if ($acompte <= 0) {
            return;
        }

        $facture = $commande->facture()->firstOrFail();

        // Mêmes contrôles qu'un encaissement (support actif, caisse dédiée pour les espèces,
        // référence unique) ; l'acompte est toujours reçu par l'agence de la précommande (D11).
        $saisie = $this->saisie->preparer($request->user(), $facture, [
            ...$data,
            'site_encaissement_id' => $commande->site_id,
        ]);

        $facture->encaissements()->create([
            ...$saisie,
            'montant' => $acompte,
            'est_acompte' => true,
            'created_by' => auth()->id(),
        ]);

        $this->auditService->record($commande, AuditEvent::ENCAISSEMENT_ADDED, auth()->user(), null, [
            'montant' => $acompte,
            'est_acompte' => true,
            'mode_paiement' => $saisie['mode_paiement'],
            'operateur_mobile_money' => $saisie['operateur_mobile_money'],
            'compte_tresorerie_id' => $saisie['compte_tresorerie_id'],
            'reference_paiement' => $saisie['reference_paiement'],
            'date_encaissement' => $saisie['date_encaissement'],
            'site_encaissement_id' => $saisie['site_encaissement_id'],
        ]);

        CommandeVenteActiviteService::log($commande, 'acompte_recu', ['montant' => $acompte]);
    }

    private static function gnf(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' GNF';
    }
}
