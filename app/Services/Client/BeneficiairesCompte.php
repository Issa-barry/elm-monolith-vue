<?php

namespace App\Services\Client;

use App\Enums\ActiviteCompte;
use App\Models\Livreur;
use App\Models\Parrain;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Services\Comptes\ActivitesCompte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tous les bénéficiaires de commissions d'un compte (ADR 0054) : propriétaire et livreur
 * (ClientIdentityResolver), parrain et consultants (ActivitesCompteResolver, rattachement posé au
 * back-office). Toute lecture de commissions de l'espace client part de ces bénéficiaires et de
 * rien d'autre : un compte ne voit jamais la part d'un autre bénéficiaire de la même opération.
 */
final class BeneficiairesCompte
{
    /**
     * @param  Collection<int, Prestataire>  $consultants
     */
    public function __construct(
        public readonly ?string $organizationId,
        public readonly ?Proprietaire $proprietaire,
        public readonly ?Livreur $livreur,
        public readonly ?Parrain $parrain,
        public readonly Collection $consultants,
    ) {}

    /** Le parrain et les consultants ne comptent que dans l'organisation du compte. */
    public static function depuis(ClientIdentity $identite, ActivitesCompte $activites): self
    {
        $organizationId = $identite->organizationId ?? $activites->organizationId;
        $memeOrganisation = $activites->organizationId !== null && $activites->organizationId === $organizationId;

        return new self(
            $organizationId,
            $identite->proprietaire,
            $identite->livreur,
            $memeOrganisation ? $activites->parrain : null,
            $memeOrganisation ? $activites->consultants : collect(),
        );
    }

    /** @return list<ActiviteCompte> Activités du compte, dans l'ordre d'affichage. */
    public function activites(): array
    {
        return array_values(array_filter([
            $this->proprietaire !== null ? ActiviteCompte::PROPRIETAIRE : null,
            $this->livreur !== null ? ActiviteCompte::LIVREUR : null,
            $this->parrain !== null ? ActiviteCompte::PARRAIN : null,
            $this->consultants->isNotEmpty() ? ActiviteCompte::CONSULTANT : null,
        ]));
    }

    public function estVide(): bool
    {
        return $this->organizationId === null || $this->activites() === [];
    }

    /** @return list<string> */
    public function idsPour(ActiviteCompte $activite): array
    {
        return match ($activite) {
            ActiviteCompte::PROPRIETAIRE => $this->proprietaire ? [(string) $this->proprietaire->id] : [],
            ActiviteCompte::LIVREUR => $this->livreur ? [(string) $this->livreur->id] : [],
            ActiviteCompte::PARRAIN => $this->parrain ? [(string) $this->parrain->id] : [],
            ActiviteCompte::CONSULTANT => $this->consultants->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        };
    }

    /**
     * Restreint une requête de parts (`beneficiaire_type`/`beneficiaire_id`) aux seuls bénéficiaires
     * de ce compte, éventuellement à une seule activité.
     */
    public function restreindreParts(Builder $query, ?ActiviteCompte $seule = null, string $prefixe = ''): void
    {
        $activites = array_filter($this->activites(), fn (ActiviteCompte $a) => $seule === null || $a === $seule);

        $query->where(function (Builder $q) use ($activites, $prefixe) {
            $q->whereRaw('1 = 0');

            foreach ($activites as $activite) {
                $q->orWhere(fn (Builder $sq) => $sq
                    ->where($prefixe.'beneficiaire_type', $activite->beneficiaireType())
                    ->whereIn($prefixe.'beneficiaire_id', $this->idsPour($activite)));
            }
        });
    }

    /** Libellé d'un consultant sur une ligne : l'entreprise, sinon la personne. */
    public function libelleConsultant(string $prestataireId): ?string
    {
        return $this->consultants->firstWhere('id', $prestataireId)?->nom_complet;
    }
}
