<?php

namespace App\Models;

use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La part individuelle finale d'un bénéficiaire sur une CommissionEnveloppe.
 * Nommée `commission_enveloppe_parts` pour ne pas entrer en collision avec
 * la table `commission_parts` de l'ancien schéma (les deux coexistent).
 */
class CommissionEnveloppePart extends Model
{
    use HasUlids;

    public const TYPE_LIVREUR = 'livreur';

    public const TYPE_PROPRIETAIRE = 'proprietaire';

    public const TYPE_EMPLOYE = 'employe';

    protected $table = 'commission_enveloppe_parts';

    protected $fillable = [
        'enveloppe_id',
        'beneficiaire_type',
        'beneficiaire_id',
        'taux_repartition_snapshot',
        'montant_brut',
        'montant_net',
        'montant_actuel',
        'statut',
        'origine',
        'validated_by',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'taux_repartition_snapshot' => 'decimal:2',
            'montant_brut' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'montant_actuel' => 'decimal:2',
            'statut' => StatutCommission::class,
            'origine' => OrigineCommissionPart::class,
            'validated_at' => 'datetime',
        ];
    }

    public function getMontantAPayerAttribute(): float
    {
        return (float) ($this->montant_actuel ?? $this->montant_net);
    }

    public function enveloppe(): BelongsTo
    {
        return $this->belongsTo(CommissionEnveloppe::class, 'enveloppe_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function resoudreBeneficiaire(): ?Model
    {
        return match ($this->beneficiaire_type) {
            self::TYPE_LIVREUR => Livreur::find($this->beneficiaire_id),
            self::TYPE_PROPRIETAIRE => Proprietaire::find($this->beneficiaire_id),
            self::TYPE_EMPLOYE => Employe::find($this->beneficiaire_id),
            default => null,
        };
    }
}
