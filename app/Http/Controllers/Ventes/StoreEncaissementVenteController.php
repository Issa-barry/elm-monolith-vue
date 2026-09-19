<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Services\AuditLogService;
use App\Services\CashbackService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class StoreEncaissementVenteController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

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
        abort_unless(
            ! $commande || $commande->isEncaissable(),
            422,
            'Le chargement doit être validé avant tout encaissement ou paiement.'
        );

        $montantRestant = $facture_vente->montant_restant;

        // mode_paiement reste la valeur générique attendue par la comptabilisation
        // (App\Services\Comptabilite\VenteComptabilisationService, PlanComptableBootstrapService,
        // CompteMappingResolver — mappings et comptes 561xxx déjà configurés autour de
        // especes/mobile_money/virement/cheque, jamais autour d'un opérateur précis). L'opérateur
        // Mobile Money (Orange Money, Kulu, Soutra Money, MOMO, PayCard) est un champ séparé,
        // uniquement présenté comme un seul select côté UI (cf. PaymentCard.vue) — le stocker
        // directement dans mode_paiement casserait la résolution du compte de trésorerie
        // (retomberait sur le compte Caisse par défaut, montant mal classé en comptabilité).
        // Référence obligatoire pour Mobile Money et Virement (rapprochement) — Chèque et
        // Espèces n'en ont pas.
        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$montantRestant}"],
            'date_encaissement' => 'nullable|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'operateur_mobile_money' => [
                'nullable', Rule::in(array_column(OperateurMobileMoney::cases(), 'value')),
                'required_if:mode_paiement,'.ModePaiement::MOBILE_MONEY->value,
            ],
            'reference_paiement' => [
                'nullable', 'string', 'max:190',
                'required_if:mode_paiement,'.ModePaiement::MOBILE_MONEY->value.','.ModePaiement::VIREMENT->value,
            ],
            'note' => 'nullable|string|max:2000',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit etre superieur a 0.',
            'montant.max' => 'Le montant ne peut pas depasser le restant du.',
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'mode_paiement.in' => 'Mode de paiement invalide.',
            'operateur_mobile_money.required_if' => 'L\'operateur Mobile Money est obligatoire.',
            'operateur_mobile_money.in' => 'Operateur Mobile Money invalide.',
            'reference_paiement.required_if' => 'La reference du paiement est obligatoire pour ce mode de paiement.',
        ]);

        $data['date_encaissement'] ??= now()->toDateString();

        // Transaction : l'encaissement, la transition de statut de la facture (donc la
        // naissance éventuelle de la commission de vente sous FACTURE_ENCAISSEE — cf.
        // FactureVente::recalculStatut()/CommissionTriggerService) et les effets en
        // cascade (auto-clôture, cashback) doivent réussir ou échouer ensemble : un
        // échec en cours de route ne doit jamais laisser une commission générée pour un
        // encaissement finalement non persisté.
        try {
            DB::transaction(function () use ($facture_vente, $commande, $data) {
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
                    'montant' => $data['montant'],
                    'date_encaissement' => $data['date_encaissement'],
                    'mode_paiement' => $data['mode_paiement'],
                    'operateur_mobile_money' => $data['operateur_mobile_money'] ?? null,
                    'reference_paiement' => $data['reference_paiement'] ?? null,
                    'note' => $data['note'] ?? null,
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
                            'operateur_mobile_money' => $data['operateur_mobile_money'] ?? null,
                            'reference_paiement' => $data['reference_paiement'] ?? null,
                            'date_encaissement' => $data['date_encaissement'],
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

                // Cashback: declenche uniquement quand la facture passe a "payee".
                if (! $etaitPayee && $estPayeeMaintenant) {
                    if ($commande && $commande->organization_id && $commande->client_id) {
                        $org = Organization::find($commande->organization_id);
                        if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
                            app(CashbackService::class)->processVente($commande);
                        }
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Encaissement non enregistré : {$e->getMessage()}"]);
        }

        return redirect()->back()->with('success', 'Encaissement enregistre.');
    }
}
