<?php

namespace App\Enums;

/**
 * Module métier d'une `App\Models\CommunicationRule` — distingue les deux
 * domaines qui déclenchent des notifications transactionnelles SMS/WhatsApp
 * (cf. rapport notifications de commande, 07/09/2026). `LOGISTIQUE` ne connaît
 * jamais de destinataire `client` (App\Models\TransfertLogistique n'a aucun
 * `client_id` — mouvement inter-sites pur), contrairement à `VENTES`.
 */
enum CommunicationModule: string
{
    case VENTES = 'ventes';
    case LOGISTIQUE = 'logistique';

    public function label(): string
    {
        return match ($this) {
            self::VENTES => 'Ventes',
            self::LOGISTIQUE => 'Logistique / Transfert',
        };
    }
}
