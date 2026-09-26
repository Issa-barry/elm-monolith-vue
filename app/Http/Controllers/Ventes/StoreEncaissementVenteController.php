<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Services\AuditLogService;
use App\Services\CashbackService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        // mode_paiement reste l'une des 4 valeurs génériques (especes/mobile_money/virement/cheque)
        // attendues par la comptabilisation — jamais un opérateur (cf. docs/encaissements.md).
        // Hors espèces, l'utilisateur choisit un SUPPORT de trésorerie de l'agence de la facture
        // (`compte_tresorerie_id`, décision du 24/09/2026) : c'est lui qui détermine le compte
        // débité et, pour le Mobile Money, l'opérateur — jamais une valeur libre venue du
        // navigateur. Référence obligatoire pour Mobile Money et Virement (rapprochement).
        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01', "max:{$montantRestant}"],
            'date_encaissement' => 'nullable|date',
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'compte_tresorerie_id' => [
                'nullable', 'string',
                'required_unless:mode_paiement,'.ModePaiement::ESPECES->value,
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
            'compte_tresorerie_id.required_unless' => 'Choisissez le compte qui reçoit ce paiement.',
            'reference_paiement.required_if' => 'La reference du paiement est obligatoire pour ce mode de paiement.',
        ]);

        // Un moyen n'est accepté que s'il figure dans la liste proposée pour l'agence de la facture
        // (support actif de cette agence, du bon type/opérateur) — même source que PaymentCard.
        $data['operateur_mobile_money'] = null;
        if ($data['mode_paiement'] === ModePaiement::ESPECES->value) {
            $data['compte_tresorerie_id'] = null;
        } else {
            $support = app(MoyensEncaissementResolver::class)->supportPour(
                $facture_vente->organization_id,
                $facture_vente->site_id,
                $data['compte_tresorerie_id'],
                $data['mode_paiement'],
            );

            if (! $support) {
                throw ValidationException::withMessages([
                    'compte_tresorerie_id' => "Ce moyen de paiement n'est pas disponible dans l'agence de cette facture : aucun support de trésorerie actif ne peut le recevoir.",
                ]);
            }

            $data['operateur_mobile_money'] = $support->operateur_mobile_money?->value;
        }

        $data['date_encaissement'] ??= now()->toDateString();

        // Espèces = argent physiquement détenu par l'auteur : il doit atterrir dans SA caisse
        // dédiée, jamais sur le compte partagé de l'agence (règle du 23/09/2026 — avant elle, un
        // agent sans caisse pouvait encaisser et l'argent retombait sur 571000 sans responsable).
        // Vérifié ici, côté serveur, quel que soit l'état du bouton désactivé dans PaymentCard :
        // le frontend n'est jamais la seule protection (CLAUDE.md §9).
        app(CaisseAgentResolver::class)->garantirCaissePourEspeces(
            $data['mode_paiement'],
            (string) auth()->id(),
            $facture_vente,
            $data['date_encaissement'],
        );

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
                    'operateur_mobile_money' => $data['operateur_mobile_money'],
                    'compte_tresorerie_id' => $data['compte_tresorerie_id'],
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
                            'operateur_mobile_money' => $data['operateur_mobile_money'],
                            'compte_tresorerie_id' => $data['compte_tresorerie_id'],
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
