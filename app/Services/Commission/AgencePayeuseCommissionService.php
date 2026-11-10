<?php

namespace App\Services\Commission;

use App\Models\CommissionPolitiquePaiement;
use App\Models\CommissionPolitiquePaiementHistorique;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use Illuminate\Support\Facades\DB;

/**
 * Agence qui PAIE une fiche de commission (ADR 0061), selon la politique de l'organisation
 * (Paramètres → Agences payeuses). Par type de bénéficiaire :
 *
 * - `centralisee` : l'agence centralisée (vide = trésorerie principale, ADR 0017) ;
 * - `rattachement` : l'agence de rattachement — celle du véhicule pour un livreur, un propriétaire
 *   ou un parrain (ADR 0020), le site lui-même pour une commission Site, aucune pour un consultant
 *   (payé alors par la trésorerie principale, DecaissementFicheResolver) ;
 * - `agence` : une agence choisie pour ce type.
 *
 * L'agence de rattachement reste portée par la fiche (`site_rattachement_id`) : elle ouvre la
 * lecture aux responsables de cette agence, jamais le paiement. Une agence configurée introuvable
 * retombe sur l'agence de rattachement, comme une organisation sans trésorerie principale.
 */
class AgencePayeuseCommissionService
{
    public const MODE_CENTRALISEE = 'centralisee';

    public const MODE_RATTACHEMENT = 'rattachement';

    public const MODE_AGENCE = 'agence';

    public const MODES = [self::MODE_CENTRALISEE, self::MODE_RATTACHEMENT, self::MODE_AGENCE];

    /** Types de bénéficiaire de fiche concernés, dans l'ordre d'affichage. */
    public const TYPES = ['proprietaire', 'prestataire', 'parrain', 'site', 'livreur'];

    public const LIBELLES = [
        'proprietaire' => 'Propriétaires',
        'prestataire' => 'Consultants',
        'parrain' => 'Parrains',
        'site' => 'Sites',
        'livreur' => 'Livreurs',
    ];

    /** Ce que désigne l'agence de rattachement pour chaque type. */
    public const RATTACHEMENTS = [
        'proprietaire' => 'Agence du véhicule',
        'prestataire' => 'Aucune : trésorerie principale',
        'parrain' => 'Agence du véhicule',
        'site' => 'Le site bénéficiaire',
        'livreur' => 'Agence du véhicule',
    ];

    /** Règles sans configuration enregistrée (décision du 07/11/2026). */
    public const DEFAUTS = [
        'proprietaire' => self::MODE_CENTRALISEE,
        'prestataire' => self::MODE_CENTRALISEE,
        'parrain' => self::MODE_CENTRALISEE,
        'site' => self::MODE_CENTRALISEE,
        'livreur' => self::MODE_RATTACHEMENT,
    ];

    /** @var array<string, array{agence_centralisee_id: ?string, regles: array<string, array{mode: string, site_id: ?string}>}> */
    private array $cache = [];

    /** @var array<string, array<int, string>> identifiants des sites par organisation */
    private array $sites = [];

    public function __construct(
        private readonly SiteCentralTresorerieResolver $siteCentral,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function oublier(): void
    {
        $this->cache = [];
        $this->sites = [];
    }

    /**
     * Politique enregistrée, complétée par les règles par défaut. `agence_centralisee_id` est la
     * valeur saisie (null = trésorerie principale).
     *
     * @return array{agence_centralisee_id: ?string, regles: array<string, array{mode: string, site_id: ?string}>}
     */
    public function politique(string $organizationId): array
    {
        if (isset($this->cache[$organizationId])) {
            return $this->cache[$organizationId];
        }

        $ligne = CommissionPolitiquePaiement::where('organization_id', $organizationId)->first();
        $enregistrees = (array) ($ligne?->regles ?? []);

        $regles = [];
        foreach (self::TYPES as $type) {
            $regle = (array) ($enregistrees[$type] ?? []);
            $mode = in_array($regle['mode'] ?? null, self::MODES, true) ? $regle['mode'] : self::DEFAUTS[$type];
            $regles[$type] = [
                'mode' => $mode,
                'site_id' => $mode === self::MODE_AGENCE ? ($regle['site_id'] ?? null) : null,
            ];
        }

        return $this->cache[$organizationId] = [
            'agence_centralisee_id' => $ligne?->agence_centralisee_id,
            'regles' => $regles,
        ];
    }

    /** Agence centralisée effective : celle saisie, sinon la trésorerie principale. */
    public function agenceCentraliseeId(string $organizationId): ?string
    {
        $saisie = $this->politique($organizationId)['agence_centralisee_id'];
        if ($saisie !== null && $this->siteExiste($organizationId, $saisie)) {
            return $saisie;
        }

        return $this->siteCentral->centralOuNull($organizationId)?->id;
    }

    /**
     * Agence unique qui paie toutes les fiches de ce type, ou null quand chaque fiche est payée par
     * son agence de rattachement.
     */
    public function siteImpose(string $organizationId, string $type): ?string
    {
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $regle = $this->politique($organizationId)['regles'][$type];

        return match ($regle['mode']) {
            self::MODE_CENTRALISEE => $this->agenceCentraliseeId($organizationId),
            self::MODE_AGENCE => $regle['site_id'] !== null && $this->siteExiste($organizationId, $regle['site_id']) ? $regle['site_id'] : null,
            default => null,
        };
    }

    /** Agence qui paie une fiche de ce type rattachée à $siteRattachement. */
    public function sitePayeur(string $organizationId, string $type, ?string $siteRattachement): ?string
    {
        return $this->siteImpose($organizationId, $type) ?? $siteRattachement;
    }

    /**
     * Agences dont l'utilisateur lit les commissions de ce type, filtrées sur l'agence de
     * rattachement. null = aucune restriction : il voit toutes les agences, ou il est rattaché à
     * l'agence qui paie toutes les fiches de ce type.
     *
     * @return array<int, string>|null
     */
    public function perimetreLecture(User $user, string $type): ?array
    {
        if ($user->voitToutesLesAgences()) {
            return null;
        }

        $siteIds = $this->siteScope->accessibleSiteIds($user)->map(fn ($id) => (string) $id)->all();
        $impose = $this->siteImpose($user->organization_id, $type);

        return $impose !== null && in_array($impose, $siteIds, true) ? null : $siteIds;
    }

    /**
     * Enregistre la politique, l'historise et fait suivre les fiches non entièrement payées : leur
     * reste à payer passe à la nouvelle agence payeuse (FicheSiteResponsableService::changerSite()),
     * les paiements déjà faits gardent leur agence.
     *
     * @param  array<string, array{mode: string, site_id?: ?string}>  $regles
     * @return int nombre de fiches qui ont changé d'agence payeuse
     */
    public function enregistrer(string $organizationId, User $user, ?string $agenceCentraliseeId, array $regles): int
    {
        return DB::transaction(function () use ($organizationId, $user, $agenceCentraliseeId, $regles) {
            $avant = $this->politique($organizationId);

            $normalisees = [];
            foreach (self::TYPES as $type) {
                $mode = $regles[$type]['mode'] ?? self::DEFAUTS[$type];
                $normalisees[$type] = [
                    'mode' => $mode,
                    'site_id' => $mode === self::MODE_AGENCE ? ($regles[$type]['site_id'] ?? null) : null,
                ];
            }

            $apres = ['agence_centralisee_id' => $agenceCentraliseeId, 'regles' => $normalisees];
            if ($apres === $avant) {
                return 0;
            }

            CommissionPolitiquePaiement::updateOrCreate(
                ['organization_id' => $organizationId],
                ['agence_centralisee_id' => $agenceCentraliseeId, 'regles' => $normalisees, 'updated_by' => $user->id],
            );
            $this->oublier();

            $reaffectees = app(FicheSiteResponsableService::class)->realignerAgencesPayeuses($organizationId);

            CommissionPolitiquePaiementHistorique::create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'avant' => $avant,
                'apres' => $apres,
                'fiches_reaffectees' => $reaffectees,
            ]);

            return $reaffectees;
        });
    }

    private function siteExiste(string $organizationId, string $siteId): bool
    {
        if (in_array($siteId, $this->sites[$organizationId] ?? [], true)) {
            return true;
        }

        if (! Site::where('organization_id', $organizationId)->whereKey($siteId)->exists()) {
            return false;
        }

        $this->sites[$organizationId][] = $siteId;

        return true;
    }
}
