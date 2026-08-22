<?php

namespace App\Exceptions\Tresorerie;

use App\Enums\StatutMouvementFonds;
use App\Models\MouvementFonds;
use RuntimeException;

class TransitionMouvementFondsInvalideException extends RuntimeException
{
    /** @param  list<StatutMouvementFonds>  $statutsAttendus */
    public static function pour(MouvementFonds $mouvement, string $action, array $statutsAttendus): self
    {
        $attendus = implode(' ou ', array_map(fn (StatutMouvementFonds $s) => $s->label(), $statutsAttendus));

        return new self(
            "Impossible de « {$action} » le mouvement {$mouvement->reference} : ".
            "statut actuel « {$mouvement->statut->label()} », attendu « {$attendus} »."
        );
    }
}
