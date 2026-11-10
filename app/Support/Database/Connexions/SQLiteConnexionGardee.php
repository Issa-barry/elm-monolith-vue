<?php

namespace App\Support\Database\Connexions;

use App\Support\Database\RefuseRequetesApresTransactionAnnulee;
use Illuminate\Database\SQLiteConnection;

class SQLiteConnexionGardee extends SQLiteConnection
{
    use RefuseRequetesApresTransactionAnnulee;
}
