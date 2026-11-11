<?php

namespace App\Support\Contacts;

use App\Models\EntrepriseTierce;
use App\Models\Personne;
use Illuminate\Http\RedirectResponse;

/**
 * Lot 9b : l'adresse d'une fiche absorbée par une fusion mène à la fiche conservée (en suivant
 * des fusions successives). Une fiche archivée sans fusion, ou dont la fiche conservée n'est pas
 * visible, reste introuvable (404).
 */
final class RedirectionFusion
{
    private const ETAPES_MAX = 10;

    /** @param  callable(Personne|EntrepriseTierce): bool  $visible */
    public static function versFicheConservee(Personne|EntrepriseTierce $fiche, string $dossier, callable $visible): RedirectResponse
    {
        $courante = $fiche;
        for ($i = 0; $i < self::ETAPES_MAX && $courante->trashed(); $i++) {
            if ($courante->fusionnee_dans_id === null) {
                abort(404);
            }
            $courante = $courante::withTrashed()->find($courante->fusionnee_dans_id) ?? abort(404);
        }

        abort_if($courante->trashed() || $courante->organization_id !== $fiche->organization_id || ! $visible($courante), 404);

        $nom = $fiche instanceof Personne ? $fiche->nom_complet : $fiche->raison_sociale;

        return redirect("/backoffice/contacts/{$dossier}/{$courante->id}")
            ->with('success', "La fiche « {$nom} » a été fusionnée dans celle-ci.");
    }
}
