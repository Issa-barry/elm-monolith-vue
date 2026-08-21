<?php

namespace App\Services;

use App\Enums\StatutFactureVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Source de vérité UNIQUE du contrôle des impayés — décision produit du 18/08/2026 : avant ce
 * service, l'aperçu (CommandeVenteController::checkSolvabilite()) et le blocage réel
 * (enforceImpayesBlocking()) recalculaient chacun la même requête indépendamment, avec un
 * risque de divergence, et le flux PDV (PdvCheckoutService) ne faisait AUCUN contrôle du tout.
 * Réutilisé identiquement par CommandeVenteController (back-office) et PdvCheckoutService (PDV) :
 * le frontend n'est plus qu'un affichage de ce que ce service calcule, jamais une garantie.
 *
 * Règle de ciblage (véhicule prioritaire, client en repli) :
 * - un `vehiculeId` renseigné → dette = somme des FactureVente.montant_restant rattachées à CE
 *   véhicule (colonne `factures_ventes.vehicule_id`, indépendante du propriétaire — deux
 *   véhicules d'un même propriétaire ont des dettes totalement indépendantes, cf. analyse du
 *   18/08/2026) ; seuil = TypeVehicule::seuil_derogation_impayes du type de CE véhicule si
 *   Vehicule::derogation_impayes_autorisee est actif ET que son type a un seuil configuré,
 *   sinon le seuil global (cf. seuilApplicableVehicule() — décision produit du 19/08/2026, en
 *   correction de la version du 18/08/2026 qui portait le montant directement sur le véhicule) ;
 * - sinon, un `clientId` renseigné → dette = factures des commandes de ce client, seuil global
 *   uniquement (pas de dérogation client) ;
 * - ni l'un ni l'autre → aucune dette (rien à contrôler).
 * Un véhicule ET un client peuvent être renseignés simultanément sur le formulaire de vente (ce
 * ne sont pas des champs mutuellement exclusifs) : dans ce cas seul le véhicule compte, le
 * client n'est jamais consulté — Ventes/Create.vue doit refléter exactement cette priorité,
 * jamais bloquer indépendamment sur les deux.
 *
 * Factures prises en compte pour le calcul de la DETTE (seuil/dérogation) : statut IMPAYEE ou
 * PARTIEL uniquement (CREEE/PAYEE/ANNULEE n'entrent jamais dans ce calcul). Le montant compté
 * est `montant_restant` (montant_net - montant_encaisse, cf. FactureVente) : une facture
 * partiellement encaissée ne compte que pour son reste à payer, jamais son montant brut.
 *
 * Verrou absolu « première régularisation » (décision produit du 20/08/2026) — indépendant du
 * calcul de dette ci-dessus et du paramètre « contrôle des impayés » : un véhicule possédant
 * déjà une facture n'ayant reçu STRICTEMENT AUCUN encaissement (montant_encaisse <= 0 ET
 * montant_restant > 0, jamais un test sur le libellé du statut — un statut CREEE compte autant
 * qu'un statut IMPAYEE, cf. premiereFactureNonEncaisseeVehicule()) ne peut recevoir aucune
 * nouvelle commande tant que cette facture n'a pas reçu au moins un encaissement, quel que soit
 * le paramétrage du contrôle de seuil. Dès qu'un encaissement — même partiel — existe sur TOUTES
 * les factures non soldées du véhicule, ce verrou n'a plus prise et seul le contrôle de seuil
 * habituel (ci-dessous) s'applique. Ne concerne que la cible 'vehicule' : un client seul (repli,
 * sans véhicule) n'est jamais soumis à ce verrou, uniquement au contrôle de seuil.
 */
class SolvabiliteService
{
    /**
     * @return array{
     *     cible: 'vehicule'|'client'|'aucun',
     *     has_debt: bool,
     *     status: 'aucun'|'partiel'|'impaye',
     *     unpaid_invoices_count: int,
     *     total_remaining: int,
     *     total_encaisse: int,
     *     last_invoice_reference: ?string,
     *     last_invoice_date: ?string,
     *     controle_actif: bool,
     *     seuil_impayes: int,
     *     montant_disponible: int,
     *     blocked: bool,
     *     depassement: int,
     *     blocage_premiere_facture: bool,
     *     facture_bloquante_reference: ?string,
     *     facture_bloquante_commande_id: ?string,
     *     factures: array<int, array{commande_id: string, reference: ?string, date: ?string, montant: int, encaisse: int, restant: int, statut: string, statut_label: string}>,
     * }
     */
    public function evaluer(string $orgId, ?string $vehiculeId, ?string $clientId): array
    {
        $controleActif = Parametre::isVentesControleImpayesActif($orgId);
        $factureBloquante = null;

        if ($vehiculeId) {
            $cible = 'vehicule';
            $seuil = $this->seuilApplicableVehicule($orgId, $vehiculeId);
            $factures = $this->facturesImpayeesVehicule($orgId, $vehiculeId);
            $factureBloquante = $this->premiereFactureNonEncaisseeVehicule($orgId, $vehiculeId);
        } elseif ($clientId) {
            $cible = 'client';
            $seuil = Parametre::getVentesSeuilImpayesMax($orgId);
            $factures = $this->facturesImpayeesClient($orgId, $clientId);
        } else {
            $cible = 'aucun';
            $seuil = Parametre::getVentesSeuilImpayesMax($orgId);
            $factures = collect();
        }

        $totalRemaining = (int) round((float) $factures->sum(fn (FactureVente $f) => $f->montant_restant));
        $totalEncaisse = (int) round((float) $factures->sum(fn (FactureVente $f) => $f->montant_encaisse));
        $hasImpayee = $factures->contains(fn (FactureVente $f) => $f->statut_facture === StatutFactureVente::IMPAYEE);
        $derniere = $factures->first();

        // Strict : bloqué seulement si la dette DÉPASSE le seuil, jamais à l'égalité (cf. cas
        // "seuil=0, dette=0 → autorisé" et "seuil=2000000, dette=2000000 → autorisé").
        $blockedSeuil = $controleActif && $totalRemaining > $seuil;
        $blockedPremiereFacture = $factureBloquante !== null;

        return [
            'cible' => $cible,
            'has_debt' => $factures->isNotEmpty(),
            'status' => $factures->isEmpty() ? 'aucun' : ($hasImpayee ? 'impaye' : 'partiel'),
            'unpaid_invoices_count' => $factures->count(),
            'total_remaining' => $totalRemaining,
            'total_encaisse' => $totalEncaisse,
            'last_invoice_reference' => $derniere?->reference,
            'last_invoice_date' => $derniere?->created_at?->format('Y-m-d'),
            'controle_actif' => $controleActif,
            'seuil_impayes' => $seuil,
            'montant_disponible' => max(0, $seuil - $totalRemaining),
            'blocked' => $blockedPremiereFacture || $blockedSeuil,
            'depassement' => $blockedSeuil ? $totalRemaining - $seuil : 0,
            'blocage_premiere_facture' => $blockedPremiereFacture,
            'facture_bloquante_reference' => $factureBloquante?->reference,
            'facture_bloquante_commande_id' => $factureBloquante?->commande_vente_id,
            'factures' => $factures->map(fn (FactureVente $f) => [
                'commande_id' => $f->commande_vente_id,
                'reference' => $f->reference,
                'date' => $f->created_at?->format('Y-m-d'),
                'montant' => (int) round((float) $f->montant_net),
                'encaisse' => (int) round($f->montant_encaisse),
                'restant' => (int) round($f->montant_restant),
                'statut' => $f->statut_facture->value,
                'statut_label' => $f->statut_facture->label(),
            ])->values()->all(),
        ];
    }

    /**
     * Évalue puis lève une ValidationException si bloqué — seul point d'entrée à utiliser avant
     * de créer une vente (CommandeVenteController::store(), PdvCheckoutService::checkout()).
     * checkSolvabilite() (aperçu) utilise evaluer() directement : il ne doit jamais lever, juste
     * refléter l'état pour l'utilisateur avant qu'il ne soumette.
     *
     * Le verrou « première régularisation » est vérifié en priorité — message dédié, distinct de
     * celui du seuil, pour ne jamais laisser croire à l'utilisateur qu'un simple encaissement
     * partiel suffirait à lever un blocage qui exige en réalité un premier encaissement complet.
     *
     * @return array (même forme que evaluer())
     */
    public function enforcerOuEchouer(string $orgId, ?string $vehiculeId, ?string $clientId): array
    {
        $resultat = $this->evaluer($orgId, $vehiculeId, $clientId);

        if ($resultat['blocage_premiere_facture']) {
            $reference = $resultat['facture_bloquante_reference'];

            throw ValidationException::withMessages([
                'impayes' => 'Ce véhicule possède déjà une commande'
                    .($reference ? " ({$reference})" : '')
                    .' dont la facture n\'a encore reçu aucun paiement. Enregistrez d\'abord un encaissement avant de créer une nouvelle commande.',
            ]);
        }

        if ($resultat['blocked']) {
            throw ValidationException::withMessages([
                'impayes' => 'Creation bloquee : le montant des impayes ('
                    .number_format($resultat['total_remaining'], 0, ',', ' ')
                    .' GNF) depasse le seuil autorise ('
                    .number_format($resultat['seuil_impayes'], 0, ',', ' ')
                    .' GNF).',
            ]);
        }

        return $resultat;
    }

    /**
     * Seuil global sauf si Vehicule::derogation_impayes_autorisee est actif ET que le type de
     * CE véhicule a un TypeVehicule::seuil_derogation_impayes configuré (cf. migrations
     * 2026_08_19_000001/000002 — décision produit du 19/08/2026, en correction de la version du
     * 18/08/2026 qui portait un montant directement sur le véhicule). Un véhicule dérogatoire
     * dont le type n'a PAS de seuil configuré retombe sur le seuil global (filet de sécurité :
     * jamais interprété comme illimité) — ce cas ne devrait normalement jamais survenir en
     * pratique, VehiculeController empêchant d'activer la dérogation tant que le type n'a pas
     * de seuil configuré, mais le seuil du type peut toujours être retiré après coup.
     *
     * Public : également appelée par VehiculeController pour afficher le seuil applicable sur
     * la fiche véhicule, sans dupliquer cette règle côté frontend.
     */
    public function seuilApplicableVehicule(string $orgId, string $vehiculeId): int
    {
        $vehicule = Vehicule::where('organization_id', $orgId)
            ->whereKey($vehiculeId)
            ->select('id', 'type_vehicule_id', 'derogation_impayes_autorisee')
            ->first();

        if (! $vehicule || ! $vehicule->derogation_impayes_autorisee) {
            return Parametre::getVentesSeuilImpayesMax($orgId);
        }

        $seuilType = TypeVehicule::where('organization_id', $orgId)
            ->whereKey($vehicule->type_vehicule_id)
            ->value('seuil_derogation_impayes');

        return $seuilType !== null ? (int) $seuilType : Parametre::getVentesSeuilImpayesMax($orgId);
    }

    /**
     * Première facture (par ordre de création) rattachée à ce véhicule n'ayant reçu STRICTEMENT
     * AUCUN encaissement alors qu'elle porte encore un solde — cf. docblock de classe. Volontai-
     * rement basé sur le calcul montant_encaisse/montant_restant plutôt que sur `statut_facture`
     * (CREEE et IMPAYEE comptent tous les deux ici) : le blocage doit rester correct même si de
     * nouveaux statuts intermédiaires apparaissent plus tard. Une facture ANNULEE n'engendre plus
     * de créance réelle et n'est jamais bloquante (cf. CommandeVenteService::annuler(), qui
     * annule désormais systématiquement la facture d'une commande annulée non encaissée).
     */
    public function premiereFactureNonEncaisseeVehicule(string $orgId, string $vehiculeId): ?FactureVente
    {
        return FactureVente::where('organization_id', $orgId)
            ->where('vehicule_id', $vehiculeId)
            ->where('statut_facture', '!=', StatutFactureVente::ANNULEE->value)
            ->with('encaissements')
            ->orderBy('created_at')
            ->get()
            ->first(fn (FactureVente $f) => (float) $f->montant_encaisse <= 0.0 && (float) $f->montant_restant > 0.0);
    }

    /** @return Collection<int, FactureVente> */
    private function facturesImpayeesVehicule(string $orgId, string $vehiculeId): Collection
    {
        return FactureVente::where('organization_id', $orgId)
            ->where('vehicule_id', $vehiculeId)
            ->whereIn('statut_facture', [StatutFactureVente::IMPAYEE->value, StatutFactureVente::PARTIEL->value])
            ->with('encaissements')
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return Collection<int, FactureVente> */
    private function facturesImpayeesClient(string $orgId, string $clientId): Collection
    {
        return FactureVente::where('organization_id', $orgId)
            ->whereIn('statut_facture', [StatutFactureVente::IMPAYEE->value, StatutFactureVente::PARTIEL->value])
            ->whereHas('commande', fn ($q) => $q->where('client_id', $clientId))
            ->with('encaissements')
            ->orderByDesc('created_at')
            ->get();
    }
}
