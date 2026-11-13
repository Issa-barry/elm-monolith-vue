<?php

namespace App\Http\Controllers\Contacts\Fusions;

use App\Http\Controllers\Controller;
use App\Models\ContactsFusion;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Services\Contacts\Fusion\FusionContacts;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Écran de fusion (ADR 0060, lot 9b) depuis la fiche A : recherche de la fiche B, comparaison et
 * arbitrage champ par champ, simulation recalculée par le serveur à chaque choix (aucune écriture).
 */
class CreateFusionController extends Controller
{
    public function __invoke(Request $request, string $type, string $fiche, FusionContacts $fusions, VisibiliteContacts $visibilite): Response
    {
        $this->authorize('fusionner', $type === ContactsFusion::TYPE_PERSONNE ? Personne::class : EntrepriseTierce::class);

        $donnees = $request->validate([
            'b' => ['nullable', 'ulid'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'choix' => ['nullable', 'array'],
            'choix.*' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $a = $fusions->charger($type, $fiche, $user);
        $recherche = trim((string) ($donnees['recherche'] ?? ''));

        $simulation = null;
        $erreur = null;
        if (! empty($donnees['b'])) {
            try {
                $simulation = $fusions->simuler($user, $type, $a->id, $donnees['b'], $donnees['choix'] ?? []);
            } catch (NotFoundHttpException) {
                $erreur = 'Fiche introuvable, archivée ou non visible.';
            } catch (ValidationException $e) {
                $erreur = collect($e->errors())->flatten()->first();
            }
        }

        return Inertia::render('Contacts/Fusion', [
            'type' => $type,
            'fiche_a' => $fusions->resume($type, $a, $user),
            'recherche' => $recherche,
            'candidats' => mb_strlen($recherche) >= 2 ? $this->candidats($type, $a, $recherche, $user, $visibilite) : [],
            'simulation' => $simulation,
            'erreur' => $erreur,
            // Jeton du formulaire : un double envoi ne fusionne qu'une fois.
            'jeton' => (string) Str::uuid(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function candidats(string $type, Personne|EntrepriseTierce $a, string $recherche, $user, VisibiliteContacts $visibilite): array
    {
        $presentation = new PresentationContacts($user->organization_id);

        if ($type === ContactsFusion::TYPE_PERSONNE) {
            $personnes = $visibilite->trierPersonnes($visibilite->rechercherPersonnes($visibilite->personnesVisibles($user), $recherche, $user))
                ->whereKeyNot($a->id)->limit(10)->get();
            $fonctions = $visibilite->fonctionsDesPersonnes($personnes, $user);

            return $personnes->map(fn (Personne $p) => [
                'id' => $p->id,
                'nom' => $presentation->nomPersonne($p, $fonctions[$p->id] ?? []),
                'telephone' => $p->telephone,
                'ville' => $p->ville,
                'fonctions' => array_column($presentation->fonctions($fonctions[$p->id] ?? []), 'libelle'),
            ])->values()->all();
        }

        $entreprises = $visibilite->rechercherEntreprises($visibilite->entreprisesVisibles($user), $recherche)
            ->whereKeyNot($a->id)->orderBy('raison_sociale')->limit(10)->get();
        $fonctions = $visibilite->fonctionsDesEntreprises($entreprises, $user);

        return $entreprises->map(fn (EntrepriseTierce $e) => [
            'id' => $e->id,
            'nom' => $e->raison_sociale,
            'telephone' => $e->telephone,
            'ville' => $e->ville,
            'fonctions' => array_column($presentation->fonctions($fonctions[$e->id] ?? []), 'libelle'),
        ])->values()->all();
    }
}
