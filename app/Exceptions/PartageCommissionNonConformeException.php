<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/** Conserve le refus de validation et expose ses détails pour l'aperçu du formulaire. */
class PartageCommissionNonConformeException extends ValidationException
{
    public array $details = [];
}
