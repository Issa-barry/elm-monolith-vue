<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suivi de livraison d'une alerte : un envoi par anomalie, destinataire, canal (application, email)
 * et vague (détection, relance). Un e-mail en échec reste « echec » et est retenté au contrôle
 * suivant, jusqu'à MAX_TENTATIVES.
 */
class AnomalieEncaissementNotification extends Model
{
    use HasUlids;

    public const CANAL_APPLICATION = 'application';

    public const CANAL_EMAIL = 'email';

    public const EN_ATTENTE = 'en_attente';

    public const ENVOYEE = 'envoyee';

    public const ECHEC = 'echec';

    public const MAX_TENTATIVES = 5;

    protected $table = 'anomalie_encaissement_notifications';

    protected $fillable = [
        'anomalie_encaissement_id',
        'user_id',
        'canal',
        'envoi',
        'statut',
        'tentatives',
        'derniere_erreur',
        'envoyee_le',
    ];

    protected function casts(): array
    {
        return [
            'tentatives' => 'integer',
            'envoyee_le' => 'datetime',
        ];
    }

    public function anomalie(): BelongsTo
    {
        return $this->belongsTo(AnomalieEncaissement::class, 'anomalie_encaissement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function libelleEnvoi(string $envoi): string
    {
        $parts = explode(':', $envoi);

        return match ($parts[0]) {
            'detection' => ($parts[1] ?? '1') === '1' ? 'Détection' : 'Nouvelle détection',
            'relance' => 'Relance '.($parts[2] ?? ''),
            default => $envoi,
        };
    }
}
