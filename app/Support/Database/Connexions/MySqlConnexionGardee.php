<?php

namespace App\Support\Database\Connexions;

use App\Support\Database\RefuseRequetesApresTransactionAnnulee;
use Illuminate\Database\MySqlConnection;

class MySqlConnexionGardee extends MySqlConnection
{
    use RefuseRequetesApresTransactionAnnulee;
}
