<?php

namespace App\Services\Comptes\Data;

use App\Enums\FicheRattachable;
use App\Enums\StatutCorrespondanceCompte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CorrespondanceCompte
{
    /**
     * @param  list<string>  $candidats  Comptes possibles quand il y en a plusieurs
     */
    public function __construct(
        public readonly FicheRattachable $type,
        public readonly Model $fiche,
        public readonly StatutCorrespondanceCompte $statut,
        public readonly ?User $compte = null,
        public readonly array $candidats = [],
        public readonly ?string $motif = null,
        public readonly ?string $preuve = null,
    ) {}

    public function estCertaine(): bool
    {
        return $this->statut === StatutCorrespondanceCompte::CERTAINE;
    }
}
