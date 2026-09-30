<?php

namespace App\Http\Controllers\Livreurs;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Personne;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Modifie la désignation et le téléphone d'un livreur depuis sa fiche — mêmes règles que la
 * saisie d'un membre dans les Équipes de livraison (EquipeLivraisonController) : téléphone
 * guinéen +224 + 9 chiffres, obligatoire pour un chauffeur, facultatif pour un convoyeur.
 *
 * Le téléphone est celui de la Personne rattachée, modifiée EN PLACE (jamais de re-résolution
 * par téléphone, même principe que UpdateClientController/UpdateParrainController) ; son unicité
 * dans l'organisation est garantie par Personne::assertTelephoneDisponible(). L'identifiant de
 * connexion d'un livreur disposant d'un compte (UserAuthIdentity) n'est jamais modifié ici.
 */
class UpdateLivreurController extends Controller
{
    public function __invoke(Request $request, Livreur $livreur): RedirectResponse
    {
        $this->authorize('update', $livreur);

        $request->merge([
            'telephone' => $request->filled('telephone')
                ? preg_replace('/\s+/', '', (string) $request->input('telephone'))
                : null,
        ]);

        $data = $request->validate([
            'nom_complet' => ['required', 'string', 'max:150'],
            'telephone' => ['nullable', 'string', 'regex:/^\+224\d{9}$/'],
        ], [
            'nom_complet.required' => 'Le nom est obligatoire.',
            'telephone.regex' => 'Le numéro doit comporter 9 chiffres après +224.',
        ]);

        $telephone = $data['telephone'] ?? null;
        $estChauffeur = $livreur->equipes()->wherePivot('role', 'chauffeur')->exists();

        if ($telephone === null && $estChauffeur) {
            throw ValidationException::withMessages([
                'telephone' => 'Le téléphone est obligatoire pour un chauffeur.',
            ]);
        }

        if ($livreur->personne) {
            if ($telephone !== null) {
                Personne::assertTelephoneDisponible($livreur->organization_id, $telephone, $livreur->personne_id);
            }
            $livreur->personne->update([
                'telephone' => $telephone,
                'telephone_normalise' => $telephone !== null ? Personne::normaliserTelephone($telephone) : null,
            ]);
        } else {
            $livreur->personne_id = Personne::resoudreOuCreer($livreur->organization_id, ['telephone' => $telephone])->id;
        }

        $livreur->nom_complet = trim($data['nom_complet']);
        $livreur->save();

        return redirect()->route('livreurs.show', $livreur)
            ->with('success', 'Livreur mis à jour.');
    }
}
