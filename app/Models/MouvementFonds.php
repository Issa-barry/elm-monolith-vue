<?php

namespace App\Models;

use App\Enums\StatutMouvementFonds;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
class MouvementFonds extends Model
{
    use HasUlids;

    protected $table = 'mouvements_fonds';

    protected $fillable = [
        'organization_id',
        'reference',
        'site_origine_id',
        'site_destination_id',
        'compte_tresorerie_origine_id',
        'compte_tresorerie_destination_id',
        'montant',
        'moyen_transfert',
        'reference_externe',
        'date_envoi',
        'date_reception',
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
            'statut' => StatutMouvementFonds::class,
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
        } while (static::where('reference', $reference)->exists());

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

    public function isTerminal(): bool
    {
        return $this->statut->isTerminal();
    }

    /** Remise agence -> siège : le site d'origine n'est pas de type siège, la destination l'est. */
    public function estRemiseAuSiege(): bool
    {
        return $this->siteDestination?->isSiege() === true && $this->siteOrigine?->isSiege() !== true;
    }

    /** Financement siège -> agence : le site d'origine est de type siège. */
    public function estFinancementDepuisSiege(): bool
    {
        return $this->siteOrigine?->isSiege() === true;
    }
}
