<?php

namespace App\Observers;

use App\Models\CommandeVente;

class VenteObserver
{
    // Le cashback est désormais déclenché au paiement complet de la facture
    // (EncaissementVenteController), pas à la création de la commande en brouillon.
}
