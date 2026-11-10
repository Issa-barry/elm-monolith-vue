<?php

namespace App\Support\Database\Connexions;

use App\Support\Database\RefuseRequetesApresTransactionAnnulee;
use Illuminate\Database\MariaDbConnection;

class MariaDbConnexionGardee extends MariaDbConnection
{
    use RefuseRequetesApresTransactionAnnulee;
}
