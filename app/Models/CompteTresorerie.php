<?php

namespace App\Models;

use App\Enums\TypeSupportTresorerie;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Support de trésorerie configurable par organisation : rattache un site à un
 * compte du plan comptable (571000 Caisse, 521000 Banque, 561xxx Mobile Money...)
 * pour lever l'ambiguïté "où l'argent est-il réellement détenu" — cf. chantier
 * Financement des agences. Aucun opérateur/numéro de compte codé en dur : une
 * organisation crée autant de supports qu'elle a de caisses/comptes réels.
 */
class CompteTresorerie extends Model
{
    use HasUlids;

    protected $table = 'compta_supports_tresorerie';

    protected $fillable = [
        'organization_id',
        'site_id',
        'compte_comptable_id',
        'type',
        'libelle',
        'moyen_paiement_defaut',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeSupportTresorerie::class,
            'actif' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CompteTresorerie $support) {
            $libelle = trim((string) $support->libelle);
            if ($libelle !== '') {
                $support->libelle = $libelle;

                return;
            }

            $type = $support->type instanceof TypeSupportTresorerie
                ? $support->type
                : TypeSupportTresorerie::from((string) $support->type);
            $siteNom = Site::whereKey($support->site_id)->value('nom') ?? '';
            $base = self::libelleBase($type, $siteNom);

            $candidat = $base;
            $suffixe = 2;
            while (static::where('organization_id', $support->organization_id)
                ->where('site_id', $support->site_id)
                ->where('libelle', $candidat)
                ->exists()) {
                $candidat = "{$base} ({$suffixe})";
                $suffixe++;
            }

            $support->libelle = $candidat;
        });
    }

    /**
     * Libellé de base généré automatiquement quand l'utilisateur n'en saisit
     * pas : "{Type} de {Site}" (ex: "Caisse de Cba"). Pure, testable sans BDD
     * — le dédoublonnage (suffixe " (2)", " (3)"...) est géré par le hook
     * creating() ci-dessus, qui a besoin d'interroger la base.
     */
    public static function libelleBase(TypeSupportTresorerie $type, string $siteNom): string
    {
        return trim("{$type->label()} de {$siteNom}");
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_comptable_id');
    }

    public function soldeOuverture(): HasOne
    {
        return $this->hasOne(SoldeOuvertureTresorerie::class);
    }

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }
}
