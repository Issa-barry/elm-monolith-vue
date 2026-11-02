<?php

namespace App\Models;

use App\Enums\StatutTransmissionPaie;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Version de la transmission mensuelle des éléments de rémunération (docs/transmission-paie.md,
 * ADR 0040). Écritures : App\Services\Rh\Paie\TransmissionPaieService.
 *
 * Une version figée (validée, transmise, remplacée) ne change plus : seuls ses changements de
 * statut et sa prise en charge par la Comptabilité sont enregistrés. Toute autre modification, ou
 * sa suppression, lève une exception — jamais de modification silencieuse.
 */
class TransmissionPaie extends Model
{
    use HasUlids;

    protected $table = 'transmissions_paie';

    public const STATUTS_FIGES = ['validee', 'transmise', 'remplacee'];

    /** Seuls champs modifiables une fois la version figée. */
    private const CHAMPS_APRES_GEL = [
        'statut', 'transmise_par', 'transmise_le',
        'prise_en_charge_par', 'prise_en_charge_le', 'prise_en_charge_reference', 'updated_at',
    ];

    protected $fillable = [
        'organization_id',
        'annee',
        'mois',
        'version',
        'statut',
        'motif',
        'version_precedente_id',
        'anomalies',
        'nb_salaries',
        'total_verse',
        'total_valorise',
        'empreinte',
        'preparee_par',
        'preparee_le',
        'validee_par',
        'validee_le',
        'transmise_par',
        'transmise_le',
        'abandonnee_par',
        'abandonnee_le',
        'prise_en_charge_par',
        'prise_en_charge_le',
        'prise_en_charge_reference',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutTransmissionPaie::class,
            'annee' => 'integer',
            'mois' => 'integer',
            'version' => 'integer',
            'anomalies' => 'array',
            'nb_salaries' => 'integer',
            'total_verse' => 'decimal:2',
            'total_valorise' => 'decimal:2',
            'preparee_le' => 'datetime',
            'validee_le' => 'datetime',
            'transmise_le' => 'datetime',
            'abandonnee_le' => 'datetime',
            'prise_en_charge_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transmission) {
            $statutAvant = StatutTransmissionPaie::from((string) $transmission->getRawOriginal('statut'));
            if (! $statutAvant->estConservee()) {
                return;
            }

            $interdits = array_diff(array_keys($transmission->getDirty()), self::CHAMPS_APRES_GEL);
            if ($interdits !== []) {
                throw new LogicException('Version de transmission figée : modification refusée ('.implode(', ', $interdits).').');
            }
        });

        static::deleting(function (self $transmission) {
            if (StatutTransmissionPaie::from((string) $transmission->getRawOriginal('statut'))->estConservee()) {
                throw new LogicException('Version de transmission figée : suppression refusée.');
            }
        });
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TransmissionPaieLigne::class)->orderBy('nom_salarie')->orderBy('ordre');
    }

    public function versionPrecedente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'version_precedente_id');
    }

    public function preparateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparee_par');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validee_par');
    }

    public function transmetteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transmise_par');
    }

    public function libelleMois(): string
    {
        $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $mois[$this->mois].' '.$this->annee;
    }

    /** @return list<array{code: string, niveau: string, employe_id: ?string, salarie: ?string, site_id: ?string, message: string}> */
    public function anomaliesListe(): array
    {
        return $this->anomalies ?? [];
    }

    public function aDesAnomaliesBloquantes(): bool
    {
        foreach ($this->anomaliesListe() as $anomalie) {
            if ($anomalie['niveau'] === 'bloquante') {
                return true;
            }
        }

        return false;
    }
}
