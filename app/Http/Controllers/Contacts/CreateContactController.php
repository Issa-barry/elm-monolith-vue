<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Contacts\QualificationsPrestataire;
use App\Services\Contacts\ReferentielCategories;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran « Nouveau contact » (ADR 0060, lot 3) : type, téléphone puis « Rechercher », identité,
 * première fonction. Refusé si l'utilisateur ne peut créer aucune fonction.
 *
 * Lot 8 : `?fonction=` présélectionne la fonction (bouton « Nouveau » des écrans Fournisseurs et
 * Prestataires) ; une fonction que l'utilisateur ne peut pas créer est ignorée.
 */
class CreateContactController extends Controller
{
    public function __invoke(Request $request, FonctionsContactEcriture $fonctions): Response
    {
        $this->authorize('create', Personne::class);

        $creables = $fonctions->fonctionsCreables($request->user());
        abort_if($creables === [], 403, 'Vous ne pouvez créer aucune fonction.');

        $organisation = $request->user()->organization_id;

        return Inertia::render('Contacts/Creer', [
            'type' => $request->query('type') === 'entreprise' ? 'entreprise' : 'personne',
            'fonction' => in_array($request->query('fonction'), $creables, true) ? $request->query('fonction') : null,
            'fonctions_creables' => array_map(fn (string $f) => ['value' => $f, 'label' => PresentationContacts::LIBELLES_FONCTIONS[$f]], $creables),
            // Lot 6b : qualifications Prestataire actives du référentiel (valeur = id).
            'types_prestataire' => QualificationsPrestataire::options($organisation),
            'sous_categories_fournisseur' => in_array('fournisseur', $creables, true) ? ReferentielCategories::optionsSousCategoriesFournisseur($organisation) : [],
        ]);
    }
}
