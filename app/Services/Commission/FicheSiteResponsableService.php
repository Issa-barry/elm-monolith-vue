<?php

namespace App\Services\Commission;

use App\Enums\StatutFichePaiement;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Services\CommissionLogistiqueService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agence d'une fiche de paiement livreur/propriétaire (décision du 06/10/2026) : l'agence qui
 * paie chacune de ses commissions est le site ACTUEL du véhicule (cf.
 * CommissionEnveloppe::siteResponsableId()). Tant qu'une fiche n'est pas entièrement payée, son
 * site suit donc les réaffectations du véhicule — y compris après la période, après sa
 * validation ou après un paiement partiel (le site n'est pas une colonne figée, ADR 0010 ; les
 * paiements déjà faits gardent leur propre site_id).
 */
class FicheSiteResponsableService
{
    private const TYPES_CONCERNES = ['livreur', 'proprietaire'];

    /**
     * Site retenu pour une fiche : celui qui pèse le plus dans le montant de ses commissions (cas
     * d'un livreur ayant travaillé sur deux véhicules d'agences différentes dans la période — la
     * fiche ne porte qu'un site). `null` si aucune commission n'a pu être rattachée à un site.
     *
     * @param  array<string, float>  $montantParSite
     */
    public static function sitePrincipal(array $montantParSite): ?string
    {
        if (empty($montantParSite)) {
            return null;
        }

        arsort($montantParSite);

        return array_key_first($montantParSite);
    }

    /** Recalcule le site de la fiche depuis ses lignes de commission, avec le site actuel des véhicules. */
    public function siteDeLaFiche(PaiementFiche $fiche): ?string
    {
        $lignes = $fiche->lignes()->where('montant', '>', 0)->get();
        $montantParId = fn (string $type) => $lignes->where('source_type', $type)
            ->mapWithKeys(fn (PaiementFicheLigne $l) => [$l->source_id => (float) $l->montant]);

        $montantParSite = [];
        $ajouter = function (?string $siteId, float $montant) use (&$montantParSite) {
            if ($siteId !== null) {
                $montantParSite[$siteId] = ($montantParSite[$siteId] ?? 0.0) + $montant;
            }
        };

        $vente = $montantParId(CommissionEnveloppePart::class);
        CommissionEnveloppePart::with('enveloppe.source.vehicule')
            ->whereIn('id', $vente->keys())
            ->get()
            ->each(fn (CommissionEnveloppePart $p) => $ajouter($p->enveloppe?->siteResponsableId(), $vente[$p->id]));

        $logistique = $montantParId(CommissionLogistiquePart::class);
        CommissionLogistiquePart::with('commission.transfert.vehicule')
            ->whereIn('id', $logistique->keys())
            ->get()
            ->each(fn (CommissionLogistiquePart $p) => $ajouter(
                $p->commission?->transfert ? CommissionLogistiqueService::resolveSiteResponsable($p->commission->transfert) : null,
                $logistique[$p->id],
            ));

        return self::sitePrincipal($montantParSite);
    }

    /**
     * Réaligne le site des fiches non payées portant une commission de ce véhicule. Appelé dès que
     * le site du véhicule change (Vehicule::booted()).
     *
     * @return int nombre de fiches dont le site a changé
     */
    public function resynchroniserPourVehicule(string $organizationId, string $vehiculeId): int
    {
        $partsVente = CommissionEnveloppePart::select('id')->whereHas(
            'enveloppe',
            fn ($q) => $q->where('organization_id', $organizationId)
                ->whereHasMorph('source', ['*'], fn ($s) => $s->where('vehicule_id', $vehiculeId)),
        );
        $partsLogistique = CommissionLogistiquePart::select('id')->whereHas(
            'commission.transfert',
            fn ($q) => $q->where('organization_id', $organizationId)->where('vehicule_id', $vehiculeId),
        );

        $fiches = PaiementFiche::where('organization_id', $organizationId)
            ->whereIn('beneficiaire_type', self::TYPES_CONCERNES)
            ->where('statut', '!=', StatutFichePaiement::PAYE->value)
            ->whereHas('lignes', fn (Builder $l) => $l->where(fn ($g) => $g
                ->where(fn ($w) => $w->where('source_type', CommissionEnveloppePart::class)->whereIn('source_id', $partsVente))
                ->orWhere(fn ($w) => $w->where('source_type', CommissionLogistiquePart::class)->whereIn('source_id', $partsLogistique))))
            ->get();

        $modifiees = 0;
        foreach ($fiches as $fiche) {
            $site = $this->siteDeLaFiche($fiche);
            if ($site !== null && $site !== $fiche->site_id) {
                $fiche->update(['site_id' => $site]);
                $modifiees++;
            }
        }

        return $modifiees;
    }
}
