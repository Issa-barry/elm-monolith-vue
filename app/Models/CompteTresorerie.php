<?php

namespace App\Models;

use App\Enums\OperateurMobileMoney;
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
 * Un support sans `agent_id` appartient à l'agence ; avec `agent_id`, il est dédié à cet agent :
 * sa caisse en espèces (type Caisse, ADR 0001) ou son compte Mobile Money (type Mobile Money, un
 * par opérateur, ADR 0027), chacun avec son propre sous-compte comptable — cf. CaisseAgentService.
 * La « nature » est donc dérivée d'`agent_id`, et tout ce qui concerne les ESPÈCES d'un agent passe
 * par caissesDediees() / isCaisseDediee(), jamais par dediees() / isDediee() seuls.
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
        'operateur_mobile_money',
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
            'operateur_mobile_money' => OperateurMobileMoney::class,
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
            $operateurAgent = $type === TypeSupportTresorerie::MOBILE_MONEY ? $support->operateur_mobile_money : null;
            $base = $support->agent_id
                ? self::libelleBaseAgent((string) User::with('personne')->find($support->agent_id)?->name, $operateurAgent)
                : self::libelleBase($type, Site::whereKey($support->site_id)->value('nom') ?? '', $support->operateur_mobile_money);

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
     * pas : "{Type} de {Site}" (ex: "Caisse de Cba"), ou "{Opérateur} de {Site}" pour un
     * Mobile Money dont l'opérateur est connu (ex: "Kulu de Matoto") — chaque opérateur ayant son
     * propre support, "Mobile Money de Matoto" ne dirait pas lequel. Pure, testable sans BDD — le
     * dédoublonnage (suffixe " (2)", " (3)"...) est géré par le hook creating() ci-dessus, qui a
     * besoin d'interroger la base.
     */
    public static function libelleBase(TypeSupportTresorerie $type, string $siteNom, OperateurMobileMoney|string|null $operateur = null): string
    {
        $operateur = is_string($operateur) ? OperateurMobileMoney::tryFrom($operateur) : $operateur;
        $nom = $type === TypeSupportTresorerie::MOBILE_MONEY && $operateur ? $operateur->label() : $type->label();

        return trim("{$nom} de {$siteNom}");
    }

    /**
     * Libellé de base d'un support dédié : « Caisse {nom de l'agent} », ou « {Opérateur} — {nom de
     * l'agent} » pour son compte Mobile Money.
     */
    public static function libelleBaseAgent(string $agentNom, OperateurMobileMoney|string|null $operateur = null): string
    {
        $operateur = is_string($operateur) ? OperateurMobileMoney::tryFrom($operateur) : $operateur;

        return $operateur ? trim("{$operateur->label()} — {$agentNom}") : trim("Caisse {$agentNom}");
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

    /** Caisse en espèces d'un agent — la seule qui reçoit ses espèces et se verse à l'agence. */
    public function isCaisseDediee(): bool
    {
        return $this->isDediee() && $this->type === TypeSupportTresorerie::CAISSE;
    }

    /** Compte Mobile Money d'un agent (ADR 0027). */
    public function isMobileMoneyDedie(): bool
    {
        return $this->isDediee() && $this->type === TypeSupportTresorerie::MOBILE_MONEY;
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

    /** Tout support dédié à un agent, caisse ou compte Mobile Money. */
    public function scopeDediees($query)
    {
        return $query->whereNotNull('agent_id');
    }

    /** Caisses en espèces des agents — jamais leurs comptes Mobile Money. */
    public function scopeCaissesDediees($query)
    {
        return $query->whereNotNull('agent_id')->where('type', TypeSupportTresorerie::CAISSE->value);
    }

    /** Comptes Mobile Money des agents (ADR 0027). */
    public function scopeMobileMoneyDedies($query)
    {
        return $query->whereNotNull('agent_id')->where('type', TypeSupportTresorerie::MOBILE_MONEY->value);
    }

    /** Ni désactivé ni jamais mis en service : brouillon ou actif. */
    public function scopeNonDesactives($query)
    {
        return $query->where(fn ($q) => $q->where('actif', true)->orWhereNull('valide_le'));
    }

    /**
     * Caisses d'agents qu'un utilisateur peut approvisionner depuis une caisse de l'agence (ADR 0018) :
     * caisses dédiées actives ; la sienne seulement si son rôle lui permet d'en confirmer lui-même la
     * réception (`tresorerie.recevoir`), sinon l'argent resterait bloqué en transit. Liste proposée
     * par les écrans — MouvementFondsService::approvisionnerCaisseAgent() revérifie chaque règle.
     */
    public function scopeApprovisionnablesPar($query, User $user)
    {
        return $query->caissesDediees()->actifs()
            ->when(! $user->can('tresorerie.recevoir'), fn ($q) => $q->where('agent_id', '!=', $user->id));
    }
}
