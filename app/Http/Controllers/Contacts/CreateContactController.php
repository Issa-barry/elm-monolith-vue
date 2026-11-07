<?php

namespace App\Http\Controllers\Contacts;

use App\Enums\PrestataireType;
use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran « Nouveau contact » (ADR 0060, lot 3) : type, téléphone puis « Rechercher », identité,
 * première fonction. Refusé si l'utilisateur ne peut créer aucune fonction.
 */
class CreateContactController extends Controller
{
    public function __invoke(Request $request, FonctionsContactEcriture $fonctions): Response
    {
        $this->authorize('create', Personne::class);

        $creables = $fonctions->fonctionsCreables($request->user());
        abort_if($creables === [], 403, 'Vous ne pouvez créer aucune fonction.');

        return Inertia::render('Contacts/Creer', [
            'type' => $request->query('type') === 'entreprise' ? 'entreprise' : 'personne',
            'fonctions_creables' => array_map(fn (string $f) => ['value' => $f, 'label' => PresentationContacts::LIBELLES_FONCTIONS[$f]], $creables),
            'types_prestataire' => PrestataireType::options(),
        ]);
    }
}
