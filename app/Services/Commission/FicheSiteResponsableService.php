<?php

namespace App\Services\Commission;

use App\Enums\StatutFichePaiement;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementFicheReaffectation;
use App\Services\CommissionLogistiqueService;
use App\Services\Comptabilite\FicheComptabilisationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Agences d'une fiche de paiement de commission :
 *
 * - agence de RATTACHEMENT (`site_rattachement_id`) : pour un livreur, un propriétaire ou un parrain,
 *   le site ACTUEL du véhicule de ses commissions (ADR 0020, CommissionEnveloppe::siteResponsableId()) ;
 * - agence qui PAIE (`site_id`) : celle de la politique de paiement de l'organisation
 *   (AgencePayeuseCommissionService, ADR 0061), qui peut être l'agence de rattachement.
 *
 * Tant qu'une fiche n'est pas entièrement payée, ses agences suivent les réaffectations du véhicule
 * et les changements de politique — y compris après la période, après sa validation ou après un
 * paiement partiel (le site n'est pas une colonne figée, ADR 0010 ; les paiements déjà faits
 * gardent leur propre site_id).
 */
class FicheSiteResponsableService
{
    /** Fiches rattachées à l'agence du véhicule ; le parrain suit le livreur dont il partage l'enveloppe (ADR 0035). */
    public const TYPES_VEHICULE = ['livreur', 'proprietaire', 'parrain'];

    public function __construct(private readonly AgencePayeuseCommissionService $agencesPayeuses) {}

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

    /**
     * Agence de rattachement d'une fiche livreur/propriétaire/parrain, recalculée depuis ses lignes
     * de commission avec le site actuel des véhicules.
     */
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
     * Agences attendues d'une fiche : [rattachement, agence qui paie]. Le rattachement d'une fiche
     * de véhicule est recalculé ; celui des autres fiches (site bénéficiaire, aucun pour un
     * consultant) ne change pas.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function sitesAttendus(PaiementFiche $fiche): array
    {
        $rattachement = in_array($fiche->beneficiaire_type, self::TYPES_VEHICULE, true)
            ? ($this->siteDeLaFiche($fiche) ?? $fiche->site_rattachement_id)
            : $fiche->site_rattachement_id;

        return [$rattachement, $this->agencesPayeuses->sitePayeur($fiche->organization_id, $fiche->beneficiaire_type, $rattachement)];
    }

    /**
     * Met une fiche non payée sur ses agences attendues.
     *
     * @return bool true si l'agence qui paie a changé
     */
    public function realigner(PaiementFiche $fiche): bool
    {
        [$rattachement, $payeur] = $this->sitesAttendus($fiche);

        if ($rattachement !== $fiche->site_rattachement_id) {
            $fiche->update(['site_rattachement_id' => $rattachement]);
        }

        if ($payeur === null || $payeur === $fiche->site_id) {
            return false;
        }

        $this->changerSite($fiche, $payeur);

        return true;
    }

    /**
     * Après un changement de politique de paiement : chaque fiche de commission non entièrement
     * payée passe à sa nouvelle agence payeuse (ADR 0061).
     *
     * @return int nombre de fiches dont l'agence qui paie a changé
     */
    public function realignerAgencesPayeuses(string $organizationId): int
    {
        $fiches = PaiementFiche::where('organization_id', $organizationId)
            ->whereIn('beneficiaire_type', AgencePayeuseCommissionService::TYPES)
            ->where('statut', '!=', StatutFichePaiement::PAYE->value)
            ->get();

        $modifiees = 0;
        foreach ($fiches as $fiche) {
            $payeur = $this->agencesPayeuses->sitePayeur($organizationId, $fiche->beneficiaire_type, $fiche->site_rattachement_id);
            if ($payeur !== null && $payeur !== $fiche->site_id) {
                $this->changerSite($fiche, $payeur);
                $modifiees++;
            }
        }

        return $modifiees;
    }

    /**
     * Réaligne les fiches non payées portant une commission de ce véhicule. Appelé dès que le site
     * du véhicule change (Vehicule::booted()).
     *
     * @return int nombre de fiches dont l'agence qui paie a changé
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
            ->whereIn('beneficiaire_type', self::TYPES_VEHICULE)
            ->where('statut', '!=', StatutFichePaiement::PAYE->value)
            ->whereHas('lignes', fn (Builder $l) => $l->where(fn ($g) => $g
                ->where(fn ($w) => $w->where('source_type', CommissionEnveloppePart::class)->whereIn('source_id', $partsVente))
                ->orWhere(fn ($w) => $w->where('source_type', CommissionLogistiquePart::class)->whereIn('source_id', $partsLogistique))))
            ->get();

        return $fiches->filter(fn (PaiementFiche $fiche) => $this->realigner($fiche))->count();
    }

    /**
     * Seul point qui change l'agence qui paie une fiche non payée. Si la fiche est déjà constatée en
     * comptabilité, son reste dû et la charge correspondante suivent dans l'agence qui la paiera
     * (FicheComptabilisationService::comptabiliserReaffectation()) — en mode shadow, comme la
     * constatation elle-même : un échec comptable est journalisé, jamais bloquant.
     */
    public function changerSite(PaiementFiche $fiche, string $siteId): void
    {
        $reaffectation = PaiementFicheReaffectation::create([
            'organization_id' => $fiche->organization_id,
            'fiche_id' => $fiche->id,
            'site_origine_id' => $fiche->site_id,
            'site_destination_id' => $siteId,
            'montant' => $fiche->montant_restant,
        ]);

        $fiche->update(['site_id' => $siteId]);

        try {
            app(FicheComptabilisationService::class)->comptabiliserReaffectation($reaffectation);
        } catch (\Throwable $e) {
            Log::error("Comptabilisation du changement d'agence de fiche échouée", [
                'fiche_id' => $fiche->id,
                'reaffectation_id' => $reaffectation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
