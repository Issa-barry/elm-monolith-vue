<?php

namespace App\Models;

use App\Enums\StatutDocumentContrat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version générée d'un contrat de travail (ADR 0044) : PDF stocké sur le disque privé `contrats_rh`
 * et instantané des données utilisées (`donnees`). Toutes les écritures passent par
 * App\Services\Rh\DocumentContratService.
 */
class DocumentContrat extends Model
{
    use HasUlids;

    protected $table = 'documents_contrat';

    protected $fillable = [
        'organization_id',
        'contrat_id',
        'version',
        'modele_contrat_id',
        'donnees',
        'fichier_path',
        'empreinte',
        'statut',
        'signe_le',
        'signature_enregistree_par',
        'signature_enregistree_le',
        'genere_par',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutDocumentContrat::class,
            'donnees' => 'array',
            'version' => 'integer',
            'signe_le' => 'date',
            'signature_enregistree_le' => 'datetime',
        ];
    }

    public function contrat(): BelongsTo
    {
        return $this->belongsTo(Contrat::class)->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'genere_par');
    }

    public function signataireEnregistreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signature_enregistree_par');
    }

    /** « CTR-373601-20260115-V2.pdf » : figé dans l'instantané à la génération. */
    public function nomFichier(): string
    {
        return ($this->donnees['reference'] ?? 'CTR-'.$this->contrat_id.'-V'.$this->version).'.pdf';
    }
}
