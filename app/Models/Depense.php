<?php

namespace App\Models;

use App\Enums\StatutDepense;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Depense extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'site_id',
        'user_id',
        'depense_type_id',
        'beneficiaire_type',
        'beneficiaire_id',
        'montant',
        'date_depense',
        'commentaire',
        'statut',
        'validateur_id',
        'date_validation',
        'motif_rejet',
        'justificatif_path',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_depense' => 'date',
            'statut' => StatutDepense::class,
            'date_validation' => 'datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function depenseType(): BelongsTo
    {
        return $this->belongsTo(DepenseType::class);
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validateur_id');
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(DepenseImputation::class);
    }

    // keyed on beneficiaire_id only — always pair with where('beneficiaire_type', X) in queries
    public function vehiculeBeneficiaire(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class, 'beneficiaire_id');
    }

    public function employeBeneficiaire(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'beneficiaire_id');
    }

    public function livreurBeneficiaire(): BelongsTo
    {
        return $this->belongsTo(Livreur::class, 'beneficiaire_id');
    }

    public function proprietaireBeneficiaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class, 'beneficiaire_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('organization_id', $orgId);
    }
}
