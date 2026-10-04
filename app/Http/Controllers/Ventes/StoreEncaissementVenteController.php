<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\Ventes\FacturePayeeCascade;
use App\Services\Ventes\SaisieEncaissementVente;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreEncaissementVenteController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly SaisieEncaissementVente $saisie,
    ) {}

    public function __invoke(Request $request, FactureVente $facture_vente): RedirectResponse
    {
        // Permission dédiée `factures.encaisser` (13/09/2026) — jusqu'ici cette route n'avait
        // AUCUN contrôle d'autorisation propre, seul le bouton désactivé côté Ventes/Show.vue
        // (can_encaisser, cf. Ventes\ShowCommandeVenteController) empêchait un utilisateur non
        // habilité d'agir : le frontend ne doit jamais être la seule protection d'une validation
        // financière (cf. CLAUDE.md §9). Vérifié en premier, avant même l'accès à la facture.
        abort_unless($request->user()->can('factures.encaisser'), 403, 'Action non autorisee.');

        abort_if($facture_vente->isAnnulee(), 422, 'Cette facture est annulee.');
        abort_unless(
            $facture_vente->organization_id === auth()->user()->organization_id,
            403,
            'Acces refuse.'
        );

        $commande = $facture_vente->commande;
        // Seule exception à « aucun encaissement avant le chargement validé » : une précommande pas
        // encore remise (ADR 0019), dont tout encaissement est un acompte (avance client 419100).
        $estAcompte = (bool) $commande?->estPrecommandeAvantRemise();
        abort_unless(
            ! $commande || $commande->isEncaissable() || $estAcompte,
            422,
            'Le chargement doit être validé avant tout encaissement ou paiement.'
        );

        $montantRestant = $facture_vente->montant_restant;

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$montantRestant}"],
            ...SaisieEncaissementVente::regles(),
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit etre superieur a 0.',
            'montant.max' => 'Le montant ne peut pas depasser le restant du.',
            ...SaisieEncaissementVente::messages(),
        ]);

        // Agence d'encaissement, support, référence Mobile Money unique et caisse dédiée pour les
        // espèces : contrôles partagés avec l'acompte de précommande (cf. SaisieEncaissementVente).
        // Un acompte est toujours reçu par l'agence de la précommande (décision D11).
        if ($estAcompte) {
            $data['site_encaissement_id'] = $commande->site_id;
        }
        $data = [...$data, ...$this->saisie->preparer($request->user(), $facture_vente, $data)];
        $siteEncaissementId = $data['site_encaissement_id'];

        // Transaction : l'encaissement, la transition de statut de la facture (donc la
        // naissance éventuelle de la commission de vente sous FACTURE_ENCAISSEE — cf.
        // FactureVente::recalculStatut()/CommissionTriggerService) et les effets en
        // cascade (auto-clôture, cashback) doivent réussir ou échouer ensemble : un
        // échec en cours de route ne doit jamais laisser une commission générée pour un
        // encaissement finalement non persisté.
        try {
            DB::transaction(function () use ($facture_vente, $commande, $data, $siteEncaissementId, $estAcompte) {
                $etaitPayee = $facture_vente->isPayee();

                // Auto-transition LIVRAISON_EN_COURS → LIVREE AVANT l'encaissement :
                // EncaissementVente::created (seul point désormais responsable de
                // recalculStatut()/cloturerSiComplete(), cf. commentaire plus bas) doit
                // trouver la commande déjà en LIVREE pour pouvoir la clôturer dans la
                // foulée si tout est complet. JAMAIS pour une commande à réception explicite (cf.
                // CommandeVente::requiertReceptionExplicite() : distribution_client depuis le
                // 30/08/2026, Grossiste + Livraison depuis le 06/09/2026) : sa transition vers
                // LIVREE exige une validation de réception explicite, indépendante de tout
                // encaissement — cf. CommandeVenteService::validerReception(). Un encaissement
                // reçu avant la réception laisse donc la commande en LIVRAISON_EN_COURS.
                if ($commande?->isLivraisonEnCours() && ! $commande->requiertReceptionExplicite()) {
                    CommandeVenteService::passerEnLivree($commande);
                    CommandeVenteActiviteService::log($commande, 'livree');
                }

                $facture_vente->encaissements()->create([
                    'site_encaissement_id' => $siteEncaissementId,
                    'montant' => $data['montant'],
                    'date_encaissement' => $data['date_encaissement'],
                    'mode_paiement' => $data['mode_paiement'],
                    'operateur_mobile_money' => $data['operateur_mobile_money'],
                    'compte_tresorerie_id' => $data['compte_tresorerie_id'],
                    'reference_paiement' => $data['reference_paiement'] ?? null,
                    'note' => $data['note'] ?? null,
                    'est_acompte' => $estAcompte,
                    'created_by' => auth()->id(),
                ]);

                // Audit: log on the parent commande
                if ($commande) {
                    $this->auditService->record(
                        $commande,
                        AuditEvent::ENCAISSEMENT_ADDED,
                        auth()->user(),
                        null,
                        [
                            'montant' => (float) $data['montant'],
                            'mode_paiement' => $data['mode_paiement'],
                            'operateur_mobile_money' => $data['operateur_mobile_money'],
                            'compte_tresorerie_id' => $data['compte_tresorerie_id'],
                            'reference_paiement' => $data['reference_paiement'] ?? null,
                            'date_encaissement' => $data['date_encaissement'],
                            'site_encaissement_id' => $siteEncaissementId,
                        ],
                    );
                }

                // recalculStatut()/cloturerSiComplete() ne sont PAS rappelés ici : le hook
                // EncaissementVente::created (app/Models/EncaissementVente.php) vient de les
                // exécuter, pour TOUTE création d'encaissement quel que soit l'appelant (ce
                // contrôleur, un import, l'API...). Un second appel ici, sur l'instance
                // $facture_vente propre à ce contrôleur (non synchronisée avec celle chargée
                // par le hook), déclenchait deux tentatives de génération de commission pour
                // le même paiement — cf. incident CMD-230826-004 (2 tentatives à 1s d'écart).
                // On se contente de rafraîchir l'état pour lire le résultat du hook.
                $facture_vente->refresh();
                $estPayeeMaintenant = $facture_vente->isPayee();

                // Cashback : déclenché uniquement quand la facture passe à « Payée » — point commun
                // avec la remise d'une précommande déjà soldée par ses acomptes.
                if (! $etaitPayee && $estPayeeMaintenant) {
                    FacturePayeeCascade::apresPassageEnPayee($commande);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // Saisie concurrente de la même référence entre le contrôle ci-dessus et l'insertion :
            // la transaction est annulée, l'utilisateur reçoit le même message (facture de l'autre
            // saisie, désormais validée) — jamais l'erreur SQL.
            if (! EncaissementVente::estDoublonReferenceMobileMoney($e)) {
                throw $e;
            }

            throw $this->saisie->referenceDejaUtilisee(
                EncaissementVente::factureUtilisantReferenceMobileMoney($facture_vente->organization_id, $data['reference_paiement']),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Encaissement non enregistré : {$e->getMessage()}"]);
        }

        return redirect()->back()->with('success', 'Encaissement enregistre.');
    }
}
