<?php

namespace App\Models;

use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\DeclencheurCommissionVente;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Parametre extends Model
{
    use HasUlids;

    // ── Groupes ───────────────────────────────────────────────────────────────
    public const GROUPE_GENERAL = 'general';

    public const GROUPE_PACKING = 'packing';

    // ── Types ─────────────────────────────────────────────────────────────────
    public const TYPE_STRING = 'string';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_JSON = 'json';

    public const TYPE_DECIMAL = 'decimal';

    // ── Groupes ───────────────────────────────────────────────────────────────
    public const GROUPE_VEHICULES = 'vehicules';

    public const GROUPE_CASHBACK = 'cashback';

    public const GROUPE_VENTES = 'ventes';

    public const GROUPE_CATALOGUE = 'catalogue';

    public const GROUPE_THEME = 'theme';

    // ── Bornes système (indépendantes de l'organisation, non contournables) ────
    public const MAX_PHOTOS_PRODUIT_SYSTEME = 50;

    public const MAX_OPTIONS_PRODUIT_SYSTEME = 10;

    public const MAX_VALEURS_OPTION_SYSTEME = 100;

    public const MAX_VARIANTES_PRODUIT_SYSTEME = 500;

    // ── Clés ──────────────────────────────────────────────────────────────────
    public const CLE_SEUIL_STOCK_FAIBLE = 'seuil_stock_faible';

    public const CLE_NOTIFICATIONS_STOCK_ACTIVES = 'notifications_stock_actives';

    public const CLE_PRIX_ROULEAU_DEFAUT = 'prix_rouleau_defaut';

    public const CLE_PRODUIT_ROULEAU_ID = 'produit_rouleau_id';

    public const CLE_TAUX_PROPRIETAIRE_DEFAUT = 'taux_proprietaire_defaut';

    public const CLE_CASHBACK_SEUIL_ACHAT = 'cashback_seuil_achat';

    public const CLE_CASHBACK_MONTANT_GAIN = 'cashback_montant_gain';

    public const CLE_VENTES_AUTORISER_SAISIE_DESSOUS_QTE_MAX = 'ventes_autoriser_saisie_dessous_qte_max';

    public const CLE_VENTES_CONTROLE_IMPAYES_ACTIF = 'ventes_controle_impayes_actif';

    public const CLE_VENTES_SEUIL_IMPAYES_MAX = 'ventes_seuil_impayes_max';

    /**
     * Politique globale d'organisation (DSI, 23/08/2026) : autoriser ou non le PDV et les
     * commandes vente à continuer quand le stock disponible est insuffisant ou nul — jamais un
     * réglage par produit (rejeté explicitement : "tous les produits vendables de
     * l'organisation suivent la même politique"), et jamais appliqué aux transferts ni aux
     * ajustements manuels (cf. TransfertLogistiqueService::checkDisponibiliteStockSource(),
     * toujours strict). Éditée depuis Paramètres > Paramètres produits (cf.
     * StockAjustementController), pas depuis l'écran générique ParametreController (groupe
     * GROUPE_VENTES exclu de cet écran) — le groupe ne détermine que la catégorisation, pas
     * l'écran d'édition.
     */
    public const CLE_VENTES_AUTORISER_STOCK_NEGATIF = 'ventes_autoriser_stock_negatif';

    /**
     * Déclencheurs de génération des commissions — cf. DeclencheurCommissionVente /
     * DeclencheurCommissionLogistique et CommissionTriggerService. Choisissent
     * uniquement QUAND la commission naît, jamais dans quel statut : elle naît
     * toujours CREEE, quel que soit le déclencheur (cf. CommissionGenerator /
     * CommissionLogistiqueService) — seule la validation de la période de
     * paiement la fait passer CREEE→IMPAYE (cf. CommissionAdjustmentService::
     * activerCommissionsCreees()). Valeur par défaut (absence de ligne en base)
     * alignée sur le comportement historique de chaque politique, jamais
     * choisie arbitrairement — voir les accesseurs ci-dessous.
     */
    public const CLE_DECLENCHEUR_COMMISSION_VENTE = 'ventes_declencheur_commission_vente';

    public const CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE = 'ventes_declencheur_commission_logistique';

    /**
     * Montant GNF/pack utilisé quand la commission logistique est générée sans saisie
     * explicite (CommissionLogistiqueService::genererAutomatique()/genererDepuisChargement()).
     * Remplace l'ancienne valeur codée en dur (200 FG) — 200 reste la valeur par défaut d'une
     * organisation n'ayant jamais explicitement configuré ce paramètre, pour ne rien changer au
     * comportement historique. Sans effet sur la saisie manuelle admin à la réception
     * (ReceptionValidationAdminController), qui continue d'exiger un montant à chaque transfert.
     */
    public const CLE_MONTANT_DEFAUT_COMMISSION_LOGISTIQUE_PAR_PACK = 'ventes_montant_defaut_commission_logistique_par_pack';

    public const CLE_MAX_PHOTOS_PRODUIT = 'max_photos_produit';

    public const CLE_MAX_OPTIONS_PRODUIT = 'max_options_produit';

    public const CLE_MAX_VALEURS_OPTION = 'max_valeurs_option';

    public const CLE_MAX_VARIANTES_PRODUIT = 'max_variantes_produit';

    // ── Thème global (preset PrimeVue / couleur principale / surface) ──────────
    // Administré via ThemeController, jamais via ParametreController générique
    // (cf. ParametreController::update() qui refuse explicitement ce groupe) —
    // la validation contre la politique de l'environnement (ThemePolicyService)
    // ne doit avoir qu'un seul point d'entrée.
    public const CLE_THEME_PRESET = 'theme_preset';

    public const CLE_THEME_PRIMARY = 'theme_primary';

    public const CLE_THEME_SURFACE = 'theme_surface';

    protected $fillable = [
        'organization_id',
        'cle',
        'valeur',
        'type',
        'groupe',
        'description',
    ];

    // ── Cache ─────────────────────────────────────────────────────────────────

    private static function cacheKey(string $orgId, string $cle): string
    {
        return "parametre_{$orgId}_{$cle}";
    }

    // ── Lecture / écriture ────────────────────────────────────────────────────

    public static function get(string $orgId, string $cle, mixed $default = null): mixed
    {
        return Cache::remember(self::cacheKey($orgId, $cle), 3600, function () use ($orgId, $cle, $default) {
            $param = static::where('organization_id', $orgId)->where('cle', $cle)->first();
            if (! $param) {
                return $default;
            }

            return self::castValue($param->valeur, $param->type);
        });
    }

    public static function set(string $orgId, string $cle, mixed $valeur): void
    {
        static::where('organization_id', $orgId)->where('cle', $cle)->update(['valeur' => (string) $valeur]);
        Cache::forget(self::cacheKey($orgId, $cle));
    }

    public static function castValue(?string $valeur, string $type): mixed
    {
        if ($valeur === null) {
            return null;
        }

        return match ($type) {
            self::TYPE_INTEGER => (int) $valeur,
            self::TYPE_DECIMAL => round((float) $valeur, 2),
            self::TYPE_BOOLEAN => in_array($valeur, ['1', 'true', 'yes'], true),
            self::TYPE_JSON => json_decode($valeur, true),
            default => $valeur,
        };
    }

    public static function clearCache(string $orgId): void
    {
        foreach ([
            self::CLE_SEUIL_STOCK_FAIBLE,
            self::CLE_NOTIFICATIONS_STOCK_ACTIVES,
            self::CLE_PRIX_ROULEAU_DEFAUT,
            self::CLE_PRODUIT_ROULEAU_ID,
            self::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            self::CLE_CASHBACK_SEUIL_ACHAT,
            self::CLE_CASHBACK_MONTANT_GAIN,
            self::CLE_VENTES_AUTORISER_SAISIE_DESSOUS_QTE_MAX,
            self::CLE_VENTES_CONTROLE_IMPAYES_ACTIF,
            self::CLE_VENTES_SEUIL_IMPAYES_MAX,
            self::CLE_VENTES_AUTORISER_STOCK_NEGATIF,
            self::CLE_DECLENCHEUR_COMMISSION_VENTE,
            self::CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE,
            self::CLE_MONTANT_DEFAUT_COMMISSION_LOGISTIQUE_PAR_PACK,
            self::CLE_MAX_PHOTOS_PRODUIT,
            self::CLE_MAX_OPTIONS_PRODUIT,
            self::CLE_MAX_VALEURS_OPTION,
            self::CLE_MAX_VARIANTES_PRODUIT,
            self::CLE_THEME_PRESET,
            self::CLE_THEME_PRIMARY,
            self::CLE_THEME_SURFACE,
        ] as $cle) {
            Cache::forget(self::cacheKey($orgId, $cle));
        }
    }

    // ── Accesseurs nommés ─────────────────────────────────────────────────────

    public static function getSeuilStockFaible(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_SEUIL_STOCK_FAIBLE, 10);
    }

    public static function isNotificationsStockActives(string $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_NOTIFICATIONS_STOCK_ACTIVES, true);
    }

    public static function getPrixRouleauDefaut(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_PRIX_ROULEAU_DEFAUT, 500);
    }

    public static function getProduitRouleauId(string $orgId): ?string
    {
        $val = self::get($orgId, self::CLE_PRODUIT_ROULEAU_ID, null);

        return $val !== null ? (int) $val : null;
    }

    public static function getTauxProprietaireDefaut(string $orgId): float
    {
        return (float) self::get($orgId, self::CLE_TAUX_PROPRIETAIRE_DEFAUT, 60);
    }

    public static function getCashbackSeuilAchat(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_CASHBACK_SEUIL_ACHAT, 500000);
    }

    public static function getCashbackMontantGain(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_CASHBACK_MONTANT_GAIN, 25000);
    }

    public static function isVentesAutorisationSaisieDessousQteMax(string $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_VENTES_AUTORISER_SAISIE_DESSOUS_QTE_MAX, true);
    }

    public static function setVentesAutorisationSaisieDessousQteMax(string $orgId, bool $valeur): void
    {
        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_VENTES_AUTORISER_SAISIE_DESSOUS_QTE_MAX],
            [
                'valeur' => $valeur ? '1' : '0',
                'type' => self::TYPE_BOOLEAN,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Autoriser la saisie d\'une quantite inferieure a la capacite du vehicule',
            ],
        );

        Cache::forget(self::cacheKey($orgId, self::CLE_VENTES_AUTORISER_SAISIE_DESSOUS_QTE_MAX));
    }

    /**
     * Défaut ACTIF (décision produit du 18/08/2026, cf. discussion contrôle des impayés) : une
     * organisation qui n'a jamais explicitement configuré ce paramètre veut, par construction,
     * la règle stricte (aucune dette tolérée par défaut, cf. getVentesSeuilImpayesMax() = 0).
     * N'affecte jamais une organisation ayant déjà une ligne `parametres` explicite (set() écrit
     * une ligne réelle, ce fallback n'est lu que si aucune ligne n'existe) — donc aucune
     * réactivation arbitraire d'un contrôle déjà désactivé volontairement.
     */
    public static function isVentesControleImpayesActif(string $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_VENTES_CONTROLE_IMPAYES_ACTIF, true);
    }

    public static function getVentesSeuilImpayesMax(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_VENTES_SEUIL_IMPAYES_MAX, 0);
    }

    public static function setVentesControleImpayes(string $orgId, bool $actif, int $seuil): void
    {
        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_VENTES_CONTROLE_IMPAYES_ACTIF],
            [
                'valeur' => $actif ? '1' : '0',
                'type' => self::TYPE_BOOLEAN,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Activer le blocage de commande sur seuil d\'impayes',
            ],
        );
        Cache::forget(self::cacheKey($orgId, self::CLE_VENTES_CONTROLE_IMPAYES_ACTIF));

        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_VENTES_SEUIL_IMPAYES_MAX],
            [
                'valeur' => (string) $seuil,
                'type' => self::TYPE_INTEGER,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Seuil maximum de dette autorise (GNF)',
            ],
        );
        Cache::forget(self::cacheKey($orgId, self::CLE_VENTES_SEUIL_IMPAYES_MAX));
    }

    /**
     * Défaut NON (false) : une organisation qui n'a jamais explicitement activé cette politique
     * garde le comportement strict — aucune vente n'est acceptée au-delà du stock disponible
     * tant qu'un admin ne l'a pas explicitement décidé (cf. décision produit du 23/08/2026).
     */
    public static function isVentesAutoriseesSansStock(string $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_VENTES_AUTORISER_STOCK_NEGATIF, false);
    }

    public static function setVentesAutoriserStockNegatif(string $orgId, bool $valeur): void
    {
        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_VENTES_AUTORISER_STOCK_NEGATIF],
            [
                'valeur' => $valeur ? '1' : '0',
                'type' => self::TYPE_BOOLEAN,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Autoriser le PDV et les commandes vente a continuer quand le stock disponible est insuffisant ou nul (le stock peut alors devenir negatif)',
            ],
        );

        Cache::forget(self::cacheKey($orgId, self::CLE_VENTES_AUTORISER_STOCK_NEGATIF));
    }

    // ── Déclencheurs de génération des commissions ──────────────────────────────

    /**
     * Défaut FACTURE_ENCAISSEE (décision produit du 18/08/2026) — n'affecte que les
     * organisations n'ayant jamais explicitement enregistré ce paramètre (cf. set() ci-dessous,
     * qui écrit une ligne réelle en base) : une organisation ayant déjà choisi CHARGEMENT_VALIDE
     * conserve son choix, jamais basculée arbitrairement par ce changement de fallback.
     */
    public static function getDeclencheurCommissionVente(string $orgId): DeclencheurCommissionVente
    {
        $valeur = self::get($orgId, self::CLE_DECLENCHEUR_COMMISSION_VENTE, DeclencheurCommissionVente::FACTURE_ENCAISSEE->value);

        return DeclencheurCommissionVente::tryFrom($valeur) ?? DeclencheurCommissionVente::FACTURE_ENCAISSEE;
    }

    public static function setDeclencheurCommissionVente(string $orgId, DeclencheurCommissionVente $declencheur): void
    {
        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_DECLENCHEUR_COMMISSION_VENTE],
            [
                'valeur' => $declencheur->value,
                'type' => self::TYPE_STRING,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Événement métier déclenchant la naissance de la commission de vente',
            ],
        );
        Cache::forget(self::cacheKey($orgId, self::CLE_DECLENCHEUR_COMMISSION_VENTE));
    }

    /**
     * Défaut RECEPTION_EFFECTUEE : comportement historique de
     * CommissionLogistiqueService::genererAutomatique(), déclenché uniquement par
     * la validation admin de la réception (ValidationAdminController::handleAccord).
     * Volontairement différent du défaut vente — cf. CommissionTriggerService.
     */
    public static function getDeclencheurCommissionLogistique(string $orgId): DeclencheurCommissionLogistique
    {
        $valeur = self::get($orgId, self::CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE->value);

        return DeclencheurCommissionLogistique::tryFrom($valeur) ?? DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE;
    }

    public static function setDeclencheurCommissionLogistique(string $orgId, DeclencheurCommissionLogistique $declencheur): void
    {
        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE],
            [
                'valeur' => $declencheur->value,
                'type' => self::TYPE_STRING,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Événement métier déclenchant la génération de la commission logistique',
            ],
        );
        Cache::forget(self::cacheKey($orgId, self::CLE_DECLENCHEUR_COMMISSION_LOGISTIQUE));
    }

    public static function getMontantDefautCommissionLogistiquePack(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_MONTANT_DEFAUT_COMMISSION_LOGISTIQUE_PAR_PACK, 200);
    }

    public static function setMontantDefautCommissionLogistiquePack(string $orgId, int $montant): void
    {
        static::updateOrCreate(
            ['organization_id' => $orgId, 'cle' => self::CLE_MONTANT_DEFAUT_COMMISSION_LOGISTIQUE_PAR_PACK],
            [
                'valeur' => (string) $montant,
                'type' => self::TYPE_INTEGER,
                'groupe' => self::GROUPE_VENTES,
                'description' => 'Montant par defaut (GNF/pack) de la commission logistique generee automatiquement',
            ],
        );
        Cache::forget(self::cacheKey($orgId, self::CLE_MONTANT_DEFAUT_COMMISSION_LOGISTIQUE_PAR_PACK));
    }

    public static function getMaxPhotosProduit(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_MAX_PHOTOS_PRODUIT, 6);
    }

    public static function getMaxOptionsProduit(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_MAX_OPTIONS_PRODUIT, 3);
    }

    public static function getMaxValeursOption(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_MAX_VALEURS_OPTION, 20);
    }

    public static function getMaxVariantesProduit(string $orgId): int
    {
        return (int) self::get($orgId, self::CLE_MAX_VARIANTES_PRODUIT, 100);
    }

    /**
     * Enregistre les 4 limites catalogue en une fois (formulaire "Configuration du catalogue").
     * Bornes système (MAX_*_SYSTEME) non contournables — à valider en amont côté FormRequest,
     * ici en dernier filet de sécurité.
     */
    public static function setLimitesCatalogue(string $orgId, int $maxPhotos, int $maxOptions, int $maxValeurs, int $maxVariantes): void
    {
        $entrees = [
            self::CLE_MAX_PHOTOS_PRODUIT => [min($maxPhotos, self::MAX_PHOTOS_PRODUIT_SYSTEME), 'Nombre maximum de photos par produit'],
            self::CLE_MAX_OPTIONS_PRODUIT => [min($maxOptions, self::MAX_OPTIONS_PRODUIT_SYSTEME), "Nombre maximum d'options par produit"],
            self::CLE_MAX_VALEURS_OPTION => [min($maxValeurs, self::MAX_VALEURS_OPTION_SYSTEME), 'Nombre maximum de valeurs par option'],
            self::CLE_MAX_VARIANTES_PRODUIT => [min($maxVariantes, self::MAX_VARIANTES_PRODUIT_SYSTEME), 'Nombre maximum de variantes générées par produit'],
        ];

        foreach ($entrees as $cle => [$valeur, $description]) {
            static::updateOrCreate(
                ['organization_id' => $orgId, 'cle' => $cle],
                [
                    'valeur' => (string) max(1, $valeur),
                    'type' => self::TYPE_INTEGER,
                    'groupe' => self::GROUPE_CATALOGUE,
                    'description' => $description,
                ],
            );
            Cache::forget(self::cacheKey($orgId, $cle));
        }
    }

    // ── Thème global ──────────────────────────────────────────────────────────

    public static function getThemePreset(string $orgId): ?string
    {
        return self::get($orgId, self::CLE_THEME_PRESET);
    }

    public static function getThemePrimary(string $orgId): ?string
    {
        return self::get($orgId, self::CLE_THEME_PRIMARY);
    }

    public static function getThemeSurface(string $orgId): ?string
    {
        return self::get($orgId, self::CLE_THEME_SURFACE);
    }

    /**
     * Enregistre les 3 axes du thème global en une fois. Ne valide PAS contre
     * la politique de l'environnement — c'est la responsabilité de l'appelant
     * (ThemeController, via UpdateThemeRequest) : ce modèle reste un mécanisme
     * de persistance générique, pas le porteur de la règle métier.
     */
    public static function setTheme(string $orgId, string $preset, string $primary, string $surface): void
    {
        $entrees = [
            self::CLE_THEME_PRESET => [$preset, 'Preset PrimeVue du thème global'],
            self::CLE_THEME_PRIMARY => [$primary, 'Couleur principale du thème global'],
            self::CLE_THEME_SURFACE => [$surface, 'Couleur de surface du thème global'],
        ];

        foreach ($entrees as $cle => [$valeur, $description]) {
            static::updateOrCreate(
                ['organization_id' => $orgId, 'cle' => $cle],
                [
                    'valeur' => $valeur,
                    'type' => self::TYPE_STRING,
                    'groupe' => self::GROUPE_THEME,
                    'description' => $description,
                ],
            );
            Cache::forget(self::cacheKey($orgId, $cle));
        }
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
