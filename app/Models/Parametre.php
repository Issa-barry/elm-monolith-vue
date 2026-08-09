<?php

namespace App\Models;

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

    public const CLE_MAX_PHOTOS_PRODUIT = 'max_photos_produit';

    public const CLE_MAX_OPTIONS_PRODUIT = 'max_options_produit';

    public const CLE_MAX_VALEURS_OPTION = 'max_valeurs_option';

    public const CLE_MAX_VARIANTES_PRODUIT = 'max_variantes_produit';

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
            self::CLE_MAX_PHOTOS_PRODUIT,
            self::CLE_MAX_OPTIONS_PRODUIT,
            self::CLE_MAX_VALEURS_OPTION,
            self::CLE_MAX_VARIANTES_PRODUIT,
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

    public static function isVentesControleImpayesActif(string $orgId): bool
    {
        return (bool) self::get($orgId, self::CLE_VENTES_CONTROLE_IMPAYES_ACTIF, false);
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

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
