<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Models\Vehicule;
use Illuminate\Http\RedirectResponse;

/**
 * Retour sur la fiche après l'ajout, le remplacement ou le retrait d'un parrain : partages laissés
 * « À faire » (plusieurs livreurs, PARRAIN-024) signalés en orange — opération autorisée, la
 * répartition de l'équipe reste à faire.
 */
final class ParrainRedirection
{
    /** @param  list<string>  $aRepartir */
    public static function apresChangement(Vehicule $vehicule, string $message, array $aRepartir): RedirectResponse
    {
        $redirect = redirect()->route('vehicules.show', $vehicule)->with('success', $message);

        if ($aRepartir !== []) {
            $redirect->with('warning', sprintf(
                'Répartition de l\'équipe à refaire dans l\'onglet Équipe : %s. Les ventes concernées sont refusées d\'ici là.',
                implode(', ', $aRepartir),
            ));
        }

        return $redirect;
    }
}
