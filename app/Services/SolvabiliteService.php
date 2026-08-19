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
 * Factures prises en compte : statut IMPAYEE ou PARTIEL uniquement (CREEE/PAYEE/ANNULEE
 * n'entrent jamais dans le calcul). Le montant compté est `montant_restant`
 * (montant_net - montant_encaisse, cf. FactureVente) : une facture partiellement encaissée ne
 * compte que pour son reste à payer, jamais son montant brut.
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
     *     factures: array<int, array{commande_id: string, reference: ?string, date: ?string, montant: int, encaisse: int, restant: int, statut: string, statut_label: string}>,
     * }
     */
    public function evaluer(string $orgId, ?string $vehiculeId, ?string $clientId): array
    {
        $controleActif = Parametre::isVentesControleImpayesActif($orgId);

        if ($vehiculeId) {
            $cible = 'vehicule';
            $seuil = $this->seuilApplicableVehicule($orgId, $vehiculeId);
            $factures = $this->facturesImpayeesVehicule($orgId, $vehiculeId);
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
        $blocked = $controleActif && $totalRemaining > $seuil;

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
            'blocked' => $blocked,
            'depassement' => $blocked ? $totalRemaining - $seuil : 0,
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
     * @return array (même forme que evaluer())
     */
    public function enforcerOuEchouer(string $orgId, ?string $vehiculeId, ?string $clientId): array
    {
        $resultat = $this->evaluer($orgId, $vehiculeId, $clientId);

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
