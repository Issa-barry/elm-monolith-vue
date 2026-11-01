<?php

namespace App\Models;

use App\Enums\StatutTransmissionPaie;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Élément de rémunération d'un salarié pour une partie du mois, copié dans une version de
 * transmission (docs/transmission-paie.md). Une ligne d'une version figée ne se modifie ni ne se
 * supprime jamais.
 */
class TransmissionPaieLigne extends Model
{
    use HasUlids;

    protected $table = 'transmission_paie_lignes';

    public const ORIGINE_CONTRAT = 'contrat';

    public const ORIGINE_AFFECTATION = 'affectation';

    public const VERSEMENT_VERSE = 'verse';

    public const VERSEMENT_VALORISE = 'valorise';

    protected $fillable = [
        'transmission_paie_id',
        'organization_id',
        'employe_id',
        'matricule',
        'nom_salarie',
        'site_id',
        'site_nom',
        'fonction_libelle',
        'contrat_id',
        'origine',
        'affectation_id',
        'saisie_id',
        'type_remuneration_id',
        'code',
        'libelle',
        'categorie',
        'versement',
        'mode_calcul',
        'periodicite',
        'nature_montant',
        'proratisable',
        'du',
        'au',
        'jours',
        'jours_mois',
        'montant_reference',
        'base_calcul',
        'taux',
        'montant',
        'detail_calcul',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'proratisable' => 'boolean',
            'du' => 'date',
            'au' => 'date',
            'jours' => 'integer',
            'jours_mois' => 'integer',
            'montant_reference' => 'decimal:2',
            'base_calcul' => 'decimal:2',
            'taux' => 'decimal:2',
            'montant' => 'decimal:2',
            'ordre' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $garde = function (self $ligne, string $action) {
            $statut = TransmissionPaie::whereKey($ligne->transmission_paie_id)->toBase()->value('statut');
            if ($statut !== null && StatutTransmissionPaie::from($statut)->estConservee()) {
                throw new LogicException("Ligne d'une version de transmission figée : {$action} refusée.");
            }
        };

        static::updating(fn (self $ligne) => $garde($ligne, 'modification'));
        static::deleting(fn (self $ligne) => $garde($ligne, 'suppression'));
    }

    public function transmission(): BelongsTo
    {
        return $this->belongsTo(TransmissionPaie::class, 'transmission_paie_id');
    }

    public function estValorisee(): bool
    {
        return $this->versement === self::VERSEMENT_VALORISE;
    }
}
