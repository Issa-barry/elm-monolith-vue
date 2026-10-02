<?php

namespace App\Observers;

class VenteObserver
{
    // Le cashback est désormais déclenché au paiement complet de la facture
    // (Ventes\StoreEncaissementVenteController), pas à la création de la commande en brouillon.
}
