<?php

namespace App\Models;

use App\Enums\GraviteAnomalieEncaissement;
use App\Enums\StatutAnomalieEncaissement;
use App\Enums\TypeAnomalieEncaissement;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Anomalie détectée par le contrôle des encaissements (docs/controle-encaissements.md, ADR 0036).
 * Identité stable : (organization_id, cle). Jamais supprimée : elle se clôt (résolue, fausse
 * alerte) et garde son historique (audit_logs) et ses notifications.
 */
class AnomalieEncaissement extends Model
{
    use HasUlids;

    protected $table = 'anomalies_encaissement';

    protected $fillable = [
        'organization_id',
        'cle',
        'type',
        'gravite',
        'statut',
        'facture_vente_id',
        'commande_vente_id',
        'site_id',
        'montant_facture',
        'montant_encaisse_net',
        'surplus',
        'agent_ids',
        'details',
        'occurrence',
        'detectee_le',
        'derniere_detection_le',
        'ecart_disparu_le',
        'pris_en_charge_par',
        'pris_en_charge_le',
        'cloture_par',
        'cloture_le',
        'commentaire_cloture',
        'nb_relances',
        'derniere_relance_le',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeAnomalieEncaissement::class,
            'gravite' => GraviteAnomalieEncaissement::class,
            'statut' => StatutAnomalieEncaissement::class,
            'montant_facture' => 'decimal:2',
            'montant_encaisse_net' => 'decimal:2',
            'surplus' => 'decimal:2',
            'agent_ids' => 'array',
            'details' => 'array',
            'occurrence' => 'integer',
            'nb_relances' => 'integer',
            'detectee_le' => 'datetime',
            'derniere_detection_le' => 'datetime',
            'ecart_disparu_le' => 'datetime',
            'pris_en_charge_le' => 'datetime',
            'cloture_le' => 'datetime',
            'derniere_relance_le' => 'datetime',
        ];
    }

    public function estOuverte(): bool
    {
        return $this->statut->estOuverte();
    }

    /** L'écart constaté a-t-il disparu au dernier contrôle ? Condition de « Résolue ». */
    public function ecartDisparu(): bool
    {
        return $this->ecart_disparu_le !== null;
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureVente::class, 'facture_vente_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function prisEnChargePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pris_en_charge_par');
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloture_par');
    }

    public function notificationsEnvoyees(): HasMany
    {
        return $this->hasMany(AnomalieEncaissementNotification::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
