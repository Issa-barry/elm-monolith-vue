<?php

namespace App\Models;

use App\Enums\StatutSupportTresorerie;
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
 *
 * Un support sans `agent_id` appartient à l'agence ; avec `agent_id`, c'est une
 * caisse dédiée à cet agent (toujours de type Caisse, avec son propre sous-compte
 * comptable — cf. CaisseAgentService). La « nature » est donc dérivée d'`agent_id`.
 *
 * Cycle de vie : un support est créé en brouillon (inutilisable), validé par un utilisateur
 * habilité (`valide_le`, `valide_par_id` — cf. SupportTresorerieValidationService), puis actif ;
 * il peut ensuite être désactivé. `actif` reste l'unique verrou d'usage : le modèle garantit
 * seulement qu'un support jamais validé ne devient jamais actif.
 */
class CompteTresorerie extends Model
{
    use HasUlids;

    protected $table = 'compta_supports_tresorerie';

    protected $fillable = [
        'organization_id',
        'site_id',
        'agent_id',
        'compte_comptable_id',
        'type',
        'libelle',
        'moyen_paiement_defaut',
        'actif',
        'valide_le',
        'valide_par_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeSupportTresorerie::class,
            'actif' => 'boolean',
            'valide_le' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (CompteTresorerie $support) {
            // À la création, `actif` absent vaut true (défaut de la colonne).
            $actif = $support->exists ? (bool) $support->actif : (bool) ($support->actif ?? true);

            if (! $actif || $support->valide_le !== null) {
                return;
            }

            // Un support créé directement actif (hors parcours d'écran : les seuls points de
            // création applicatifs passent explicitement `actif => false`) est réputé validé.
            if (! $support->exists) {
                $support->valide_le = now();

                return;
            }

            // Jamais d'activation d'un brouillon en contournant la validation (cf.
            // SupportTresorerieValidationService::valider()) : c'est une erreur de programmation,
            // les écrans reçoivent un message de validation avant d'arriver ici.
            throw new \LogicException('Un support de trésorerie non validé ne peut pas être actif : il doit être validé.');
        });

        static::creating(function (CompteTresorerie $support) {
            $libelle = trim((string) $support->libelle);
            if ($libelle !== '') {
                $support->libelle = $libelle;

                return;
            }

            $type = $support->type instanceof TypeSupportTresorerie
                ? $support->type
                : TypeSupportTresorerie::from((string) $support->type);
            $base = $support->agent_id
                ? self::libelleBaseAgent((string) User::with('personne')->find($support->agent_id)?->name)
                : self::libelleBase($type, Site::whereKey($support->site_id)->value('nom') ?? '');

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

    /** Libellé de base d'une caisse dédiée : « Caisse {nom de l'agent} ». */
    public static function libelleBaseAgent(string $agentNom): string
    {
        return trim("Caisse {$agentNom}");
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function isDediee(): bool
    {
        return $this->agent_id !== null;
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }

    /** Un support validé a quitté le brouillon (il peut ensuite être actif ou désactivé). */
    public function estValide(): bool
    {
        return $this->valide_le !== null;
    }

    public function statut(): StatutSupportTresorerie
    {
        if (! $this->estValide()) {
            return StatutSupportTresorerie::BROUILLON;
        }

        return $this->actif ? StatutSupportTresorerie::ACTIF : StatutSupportTresorerie::INACTIF;
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

    /** Supports de l'agence (sans responsable) — les seuls comptés dans le « disponible » d'un site. */
    public function scopeAgence($query)
    {
        return $query->whereNull('agent_id');
    }

    public function scopeDediees($query)
    {
        return $query->whereNotNull('agent_id');
    }
}
