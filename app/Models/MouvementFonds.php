<?php

namespace App\Models;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document métier du transfert d'argent entre deux sites (agence -> siège =
 * remise ; siège -> agence = financement). Workflow brouillon -> envoyé ->
 * reçu, cf. StatutMouvementFonds. Ne PAS confondre avec TransfertLogistique
 * (transfert de marchandises entre agences) : deux notions totalement
 * distinctes qui partagent juste le mot "transfert" dans le vocabulaire métier.
 *
 * Chaque transition transactionnelle est portée par MouvementFondsService,
 * jamais directement par le modèle (garde-fous idempotence/permissions/
 * verrouillage de période comptable centralisés là-bas).
 *
 * `nature` distingue le mouvement entre agences (`inter_sites`, historique), le versement d'une
 * caisse dédiée à un agent vers une caisse de l'agence (`interne_caisses`, même site), son sens
 * inverse, l'approvisionnement de la caisse d'un agent (`approvisionnement_caisse`, ADR 0018) et le
 * règlement inter-agences (`reglement_agences`, lié à des encaissements précis) — cf.
 * NatureMouvementFonds. `date_envoi`/`date_reception` sont des jours ; `sent_at`/`received_at`
 * portent l'heure réelle de chaque étape (renseignés depuis le 04/10/2026).
 */
class MouvementFonds extends Model
{
    use HasUlids;

    protected $table = 'mouvements_fonds';

    protected $fillable = [
        'organization_id',
        'reference',
        'nature',
        'site_origine_id',
        'site_destination_id',
        'compte_tresorerie_origine_id',
        'compte_tresorerie_destination_id',
        'montant',
        'moyen_transfert',
        'reference_externe',
        'echeance_debut',
        'echeance_fin',
        'date_envoi',
        'date_reception',
        'sent_at',
        'received_at',
        'justificatif_path',
        'commentaire',
        'statut',
        'motif_annulation',
        'created_by',
        'sent_by',
        'received_by',
        'cancelled_by',
        'piece_comptable_envoi_id',
        'piece_comptable_reception_id',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_envoi' => 'date',
            'date_reception' => 'date',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'echeance_debut' => 'date',
            'echeance_fin' => 'date',
            'statut' => StatutMouvementFonds::class,
            'nature' => NatureMouvementFonds::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->reference)) {
                $m->reference = static::genererReference($m->organization_id);
            }
        });
    }

    private static function genererReference(string $organizationId): string
    {
        $annee = now()->year;
        $prefix = "MVT-{$annee}-";
        $num = static::where('organization_id', $organizationId)->whereYear('created_at', $annee)->count() + 1;

        do {
            $reference = $prefix.str_pad((string) $num, 5, '0', STR_PAD_LEFT);
            $num++;
        } while (static::where('organization_id', $organizationId)->where('reference', $reference)->exists());

        return $reference;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function siteOrigine(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_origine_id');
    }

    public function siteDestination(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_destination_id');
    }

    public function compteTresorerieOrigine(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_tresorerie_origine_id');
    }

    public function compteTresorerieDestination(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_tresorerie_destination_id');
    }

    public function pieceEnvoi(): BelongsTo
    {
        return $this->belongsTo(PieceComptable::class, 'piece_comptable_envoi_id');
    }

    public function pieceReception(): BelongsTo
    {
        return $this->belongsTo(PieceComptable::class, 'piece_comptable_reception_id');
    }

    /** Encaissements reversés par un règlement inter-agences (vide pour les autres natures). */
    public function lignesReglement(): HasMany
    {
        return $this->hasMany(MouvementFondsEncaissement::class, 'mouvement_fonds_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receptionnaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isBrouillon(): bool
    {
        return $this->statut === StatutMouvementFonds::BROUILLON;
    }

    public function isEnvoye(): bool
    {
        return $this->statut === StatutMouvementFonds::ENVOYE;
    }

    public function isConteste(): bool
    {
        return $this->statut === StatutMouvementFonds::CONTESTE;
    }

    public function isTerminal(): bool
    {
        return $this->statut->isTerminal();
    }

    public function isInterne(): bool
    {
        return $this->nature === NatureMouvementFonds::INTERNE_CAISSES;
    }

    public function isReglementAgences(): bool
    {
        return $this->nature === NatureMouvementFonds::REGLEMENT_AGENCES;
    }

    public function isApprovisionnement(): bool
    {
        return $this->nature === NatureMouvementFonds::APPROVISIONNEMENT_CAISSE;
    }

    /** Agent titulaire de la caisse destinataire d'un approvisionnement (null pour toute autre nature). */
    public function beneficiaireId(): ?string
    {
        return $this->isApprovisionnement() ? $this->compteTresorerieDestination?->agent_id : null;
    }

    /**
     * Réception d'un approvisionnement (ADR 0018) : seul l'agent titulaire de la caisse destinataire
     * confirme ou conteste — jamais un tiers ni un administrateur à sa place. S'il a lui-même remis
     * l'argent (responsable qui gère sa caisse et celle de l'agence), il le peut si son rôle a la
     * permission correspondante, comme pour un versement (auto-confirmation tracée, ADR 0018 révisé
     * le 04/10/2026). Règle d'identité vérifiée hors du Gate pour que le bypass super admin ne
     * permette pas de confirmer à la place d'un autre agent.
     */
    public function receptionReserveeA(User $user, string $permissionSiRemettant = 'tresorerie.recevoir'): bool
    {
        $beneficiaire = $this->beneficiaireId();

        return $beneficiaire !== null
            && $user->organization_id === $this->organization_id
            && $user->id === $beneficiaire
            && ($user->id !== $this->sent_by || $user->can($permissionSiRemettant));
    }

    /**
     * Versement ou approvisionnement de caisse dont la réception a été confirmée par la personne qui
     * l'a envoyé — autorisé si son rôle a `tresorerie.recevoir` (ADR 0001, ADR 0018) : simple
     * information de traçabilité affichée dans Mouvements (« Confirmé par l'expéditeur »), jamais
     * un blocage.
     */
    public function confirmeParExpediteur(): bool
    {
        return ($this->isInterne() || $this->isApprovisionnement())
            && $this->sent_by !== null
            && $this->received_by !== null
            && $this->sent_by === $this->received_by;
    }

    /** Remise agence -> siège : la destination est le site central de trésorerie, pas l'origine (ADR 0017). */
    public function estRemiseAuSiege(): bool
    {
        return $this->siteDestination?->isCentralTresorerie() === true && $this->siteOrigine?->isCentralTresorerie() !== true;
    }

    /** Financement siège -> agence : l'origine est le site central de trésorerie (ADR 0017). */
    public function estFinancementDepuisSiege(): bool
    {
        return $this->siteOrigine?->isCentralTresorerie() === true;
    }
}
