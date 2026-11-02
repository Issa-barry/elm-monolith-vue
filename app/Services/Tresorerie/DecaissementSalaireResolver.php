<?php

namespace App\Services\Tresorerie;

use App\Models\CompteTresorerie;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Paiement d'un salaire = décaissement réel (ADR 0049), sur le modèle des fiches de commission
 * (ADR 0009). Délègue à DecaissementSupportResolver et n'ajoute que ce qui est propre au salaire :
 * son agence de trésorerie, celle du salarié. Un salarié sans agence est payé depuis le site central
 * de trésorerie (ADR 0017) — jamais depuis l'agence de l'utilisateur, jamais un choix manuel. Sans
 * site central, le paiement est impossible.
 */
class DecaissementSalaireResolver
{
    public function __construct(
        private readonly DecaissementSupportResolver $supports,
        private readonly SiteCentralTresorerieResolver $siteCentral,
    ) {}

    /** Agence d'où sort l'argent, ou null si le salarié n'a pas d'agence et qu'aucun site central n'existe. */
    public function siteTresorerie(Employe $employe): ?string
    {
        return $employe->site_id ?? $this->siteCentral->centralOuNull($employe->organization_id)?->id;
    }

    /**
     * Données du dialogue de paiement (PaymentCard, sens décaissement), par id de salarié.
     *
     * @param  Collection<int, Employe>  $employes
     * @return array<string, array{site_id: ?string, moyens: list<array<string, mixed>>, especes_disponibles: bool, solde_especes: ?float, message: ?string}>
     */
    public function optionsPourEmployes(Collection $employes, User $payeur): array
    {
        if ($employes->isEmpty()) {
            return [];
        }

        $sites = $employes->mapWithKeys(fn (Employe $e) => [$e->id => $this->siteTresorerie($e)]);

        return $this->supports->optionsParCle($payeur->organization_id, $sites, $payeur, DecaissementFicheResolver::MESSAGE_SANS_SITE_CENTRAL);
    }

    /**
     * Support débité pour ce paiement, ou exception de validation — contrôle serveur, indépendant de
     * ce que l'écran a proposé.
     *
     * @throws ValidationException
     */
    public function supportPour(Employe $employe, User $payeur, string $modePaiement, ?string $compteTresorerieId): CompteTresorerie
    {
        $siteId = $this->siteTresorerie($employe);
        if (! $siteId) {
            throw ValidationException::withMessages(['compte_tresorerie_id' => DecaissementFicheResolver::MESSAGE_SANS_SITE_CENTRAL]);
        }

        return $this->supports->supportPour($employe->organization_id, $siteId, $payeur, $modePaiement, $compteTresorerieId);
    }

    public function referenceRequise(Employe $employe, string $modePaiement, ?string $compteTresorerieId): bool
    {
        return $this->supports->referenceRequise($employe->organization_id, $this->siteTresorerie($employe), $modePaiement, $compteTresorerieId);
    }
}
