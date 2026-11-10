<?php

namespace App\Services\Client\Operations;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppePart;
use App\Models\EquipeLivreur;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\Client\ClientIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Périmètre unique des opérations d'un compte (ADR 0058) : la liste « Commandes », son détail et le
 * scan lisent tous ici ce qu'un compte peut voir et pourquoi. Une opération est lue une seule fois en
 * base, avec ses conditions combinées : un compte à la fois client, livreur et propriétaire de la même
 * vente voit une seule ligne, portant ses trois liens. Le lien vient des données du compte, jamais de
 * ses rôles : un super admin n'y gagne rien.
 *
 * Lien livreur : il n'existe aucun historique des membres d'équipe, et `equipe_livreurs` est recréée à
 * chaque modification d'équipe. Un livreur voit donc une opération s'il a une part de commission à son
 * nom, si elle est en cours et confiée à son équipe actuelle, ou si elle est confiée à cette équipe et
 * datée de son entrée au plus tôt. `created_at` peut être postérieur à l'arrivée réelle, jamais
 * antérieur : l'erreur possible masque une opération, elle n'en révèle aucune.
 */
final class OperationsCompte
{
    public const LIEN_CLIENT = 'client';

    public const LIEN_LIVREUR = 'livreur';

    public const LIEN_PROPRIETAIRE = 'proprietaire';

    public const DATE_VENTE = 'COALESCE(validated_at, created_at)';

    public const DATE_TRANSFERT = 'COALESCE(date_depart_reelle, created_at)';

    private const VENTES_EN_COURS = [
        StatutCommandeVente::A_CHARGER,
        StatutCommandeVente::CHARGEMENT_EN_COURS,
        StatutCommandeVente::LIVRAISON_EN_COURS,
    ];

    private const TRANSFERTS_EN_COURS = [
        StatutTransfert::BROUILLON,
        StatutTransfert::CHARGEMENT,
        StatutTransfert::TRANSIT,
    ];

    /** @var Collection<int, string>|null */
    private ?Collection $vehiculesProprietaire = null;

    /** @var Collection<int, EquipeLivreur>|null */
    private ?Collection $affectations = null;

    public function __construct(private readonly ClientIdentity $identity) {}

    /**
     * `$lien` restreint aux opérations vues à ce titre (filtre de la liste), les autres liens de chaque
     * ligne restent calculés par `liensVentes()`.
     *
     * @return Builder<CommandeVente>
     */
    public function ventes(?string $lien = null): Builder
    {
        $query = CommandeVente::query();
        $conditions = $this->restreindre($this->conditionsVente(), $lien);

        if ($this->identity->organizationId === null || $conditions === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('organization_id', $this->identity->organizationId)
            ->where(function (Builder $q) use ($conditions) {
                foreach ($conditions as $condition) {
                    $q->orWhere($condition);
                }
            });
    }

    /** @return Builder<TransfertLogistique> */
    public function transferts(?string $lien = null): Builder
    {
        $query = TransfertLogistique::query();
        $conditions = $this->restreindre($this->conditionsTransfert(), $lien);

        if ($this->identity->organizationId === null || $conditions === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('organization_id', $this->identity->organizationId)
            ->where(function (Builder $q) use ($conditions) {
                foreach ($conditions as $condition) {
                    $q->orWhere($condition);
                }
            });
    }

    public function aDesOperations(): bool
    {
        return $this->ventes()->exists() || $this->transferts()->exists();
    }

    /**
     * @param  Collection<int, CommandeVente>  $ventes
     * @return array<string, list<string>>
     */
    public function liensVentes(Collection $ventes): array
    {
        $viaLivreur = $this->identity->livreur === null || $ventes->isEmpty()
            ? collect()
            : CommandeVente::query()
                ->whereKey($ventes->pluck('id')->all())
                ->where(fn (Builder $q) => $this->conditionLivreurVente($q))
                ->pluck('id')
                ->flip();

        $vehicules = $this->vehiculesProprietaire()->flip();
        $clientId = $this->identity->client?->id;

        return $ventes->mapWithKeys(fn (CommandeVente $vente) => [
            $vente->id => array_values(array_filter([
                $clientId !== null && $vente->client_id === $clientId && ! $vente->isBrouillon() ? self::LIEN_CLIENT : null,
                $viaLivreur->has($vente->id) ? self::LIEN_LIVREUR : null,
                $vente->vehicule_id !== null && $vehicules->has($vente->vehicule_id) ? self::LIEN_PROPRIETAIRE : null,
            ])),
        ])->all();
    }

    /**
     * @param  Collection<int, TransfertLogistique>  $transferts
     * @return array<string, list<string>>
     */
    public function liensTransferts(Collection $transferts): array
    {
        $viaLivreur = $this->identity->livreur === null || $transferts->isEmpty()
            ? collect()
            : TransfertLogistique::query()
                ->whereKey($transferts->pluck('id')->all())
                ->where(fn (Builder $q) => $this->conditionLivreurTransfert($q))
                ->pluck('id')
                ->flip();

        $vehicules = $this->vehiculesProprietaire()->flip();

        return $transferts->mapWithKeys(fn (TransfertLogistique $transfert) => [
            $transfert->id => array_values(array_filter([
                $viaLivreur->has($transfert->id) ? self::LIEN_LIVREUR : null,
                $transfert->vehicule_id !== null && $vehicules->has($transfert->vehicule_id) ? self::LIEN_PROPRIETAIRE : null,
            ])),
        ])->all();
    }

    /**
     * @param  array<string, \Closure(Builder): void>  $conditions
     * @return array<string, \Closure(Builder): void>
     */
    private function restreindre(array $conditions, ?string $lien): array
    {
        return $lien === null ? $conditions : array_intersect_key($conditions, [$lien => true]);
    }

    /** @return array<string, \Closure(Builder): void> */
    private function conditionsVente(): array
    {
        $conditions = [];

        if ($this->identity->client !== null) {
            $conditions[self::LIEN_CLIENT] = fn (Builder $q) => $q
                ->where('client_id', $this->identity->client->id)
                ->where('statut', '!=', StatutCommandeVente::BROUILLON->value);
        }

        if ($this->identity->livreur !== null) {
            $conditions[self::LIEN_LIVREUR] = fn (Builder $q) => $this->conditionLivreurVente($q);
        }

        if ($this->vehiculesProprietaire()->isNotEmpty()) {
            $conditions[self::LIEN_PROPRIETAIRE] = fn (Builder $q) => $q->whereIn('vehicule_id', $this->vehiculesProprietaire());
        }

        return $conditions;
    }

    /** @return array<string, \Closure(Builder): void> */
    private function conditionsTransfert(): array
    {
        $conditions = [];

        if ($this->identity->livreur !== null) {
            $conditions[self::LIEN_LIVREUR] = fn (Builder $q) => $this->conditionLivreurTransfert($q);
        }

        if ($this->vehiculesProprietaire()->isNotEmpty()) {
            $conditions[self::LIEN_PROPRIETAIRE] = fn (Builder $q) => $q->whereIn('vehicule_id', $this->vehiculesProprietaire());
        }

        return $conditions;
    }

    private function conditionLivreurVente(Builder $query): void
    {
        $livreurId = $this->identity->livreur->id;

        $query
            ->where('statut', '!=', StatutCommandeVente::BROUILLON->value)
            ->where(function (Builder $q) use ($livreurId) {
                // Une part annulée ne prouve rien : un recalcul de partage peut l'avoir retirée.
                $q->whereHas('commissions.parts', fn (Builder $part) => $part
                    ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
                    ->where('beneficiaire_id', $livreurId)
                    ->where('statut', '!=', StatutCommission::ANNULEE->value));

                $this->ouAffectationActuelle($q, self::VENTES_EN_COURS, self::DATE_VENTE);
            });
    }

    private function conditionLivreurTransfert(Builder $query): void
    {
        $livreurId = $this->identity->livreur->id;

        $query->where(function (Builder $q) use ($livreurId) {
            $q->whereHas('commissions.parts', fn (Builder $part) => $part
                ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
                ->where('beneficiaire_id', $livreurId)
                ->where('statut', '!=', StatutCommission::ANNULEE->value));

            // Ancien moteur des commissions logistiques, encore lu pour l'historique.
            $q->orWhereHas('commission.parts', fn (Builder $part) => $part
                ->where('livreur_id', $livreurId)
                ->where('statut', '!=', StatutCommission::ANNULEE->value));

            $this->ouAffectationActuelle($q, self::TRANSFERTS_EN_COURS, self::DATE_TRANSFERT);
        });
    }

    /**
     * Comparaison au jour : `date_depart_reelle` n'a pas d'heure, un départ le jour de l'arrivée dans
     * l'équipe doit rester visible.
     *
     * @param  list<\BackedEnum>  $statutsEnCours
     */
    private function ouAffectationActuelle(Builder $query, array $statutsEnCours, string $colonneDate): void
    {
        foreach ($this->affectations() as $affectation) {
            $query->orWhere(function (Builder $q) use ($affectation, $statutsEnCours, $colonneDate) {
                $q->where(fn (Builder $equipe) => $equipe
                    ->where('equipe_livraison_id', $affectation->equipe_id)
                    ->orWhere(fn (Builder $repli) => $repli
                        ->whereNull('equipe_livraison_id')
                        ->where('vehicule_id', $affectation->equipe->vehicule_id)));

                $q->where(function (Builder $periode) use ($affectation, $statutsEnCours, $colonneDate) {
                    $periode->whereIn('statut', array_map(fn (\BackedEnum $s) => $s->value, $statutsEnCours));

                    if ($affectation->created_at !== null) {
                        $periode->orWhereRaw("DATE({$colonneDate}) >= DATE(?)", [$affectation->created_at->toDateTimeString()]);
                    }
                });
            });
        }
    }

    /** @return Collection<int, string> */
    private function vehiculesProprietaire(): Collection
    {
        if ($this->vehiculesProprietaire !== null) {
            return $this->vehiculesProprietaire;
        }

        return $this->vehiculesProprietaire = $this->identity->proprietaire === null || $this->identity->organizationId === null
            ? collect()
            : Vehicule::query()
                ->where('organization_id', $this->identity->organizationId)
                ->where('proprietaire_id', $this->identity->proprietaire->id)
                ->pluck('id');
    }

    /** @return Collection<int, EquipeLivreur> */
    private function affectations(): Collection
    {
        if ($this->affectations !== null) {
            return $this->affectations;
        }

        return $this->affectations = EquipeLivreur::query()
            ->where('livreur_id', $this->identity->livreur->id)
            ->whereHas('equipe', fn (Builder $q) => $q->where('organization_id', $this->identity->organizationId))
            ->with('equipe:id,vehicule_id')
            ->get();
    }
}
