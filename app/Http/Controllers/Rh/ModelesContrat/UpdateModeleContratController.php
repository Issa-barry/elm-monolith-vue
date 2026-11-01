<?php

namespace App\Http\Controllers\Rh\ModelesContrat;

use App\Enums\TypeContrat;
use App\Http\Controllers\Controller;
use App\Services\Rh\ModeleContratService;
use App\Support\Rh\Contrats\VariablesContrat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre le texte d'un modèle (ADR 0044). Texte brut uniquement ; une variable inconnue, ou
 * réservée aux CDD dans un modèle CDI, est refusée. Les contrats déjà générés ne changent pas.
 */
class UpdateModeleContratController extends Controller
{
    public function __invoke(Request $request, string $type, ModeleContratService $modeles): RedirectResponse
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);
        $typeContrat = TypeContrat::tryFrom($type) ?? abort(404);

        $data = $request->validate([
            'titre' => ['required', 'string', 'max:150'],
            'articles' => ['required', 'array', 'min:1', 'max:40'],
            'articles.*.titre' => ['required', 'string', 'max:150'],
            'articles.*.texte' => ['required', 'string', 'max:5000'],
        ], [
            'titre.required' => 'L’intitulé du contrat est obligatoire.',
            'articles.required' => 'Le modèle doit contenir au moins un article.',
            'articles.min' => 'Le modèle doit contenir au moins un article.',
            'articles.max' => 'Le modèle est limité à 40 articles.',
            'articles.*.titre.required' => 'Chaque article doit avoir un titre.',
            'articles.*.texte.required' => 'Chaque article doit avoir un texte.',
            'articles.*.texte.max' => 'Le texte d’un article est limité à 5 000 caractères.',
        ]);

        $articles = array_map(fn (array $a) => ['titre' => trim($a['titre']), 'texte' => trim($a['texte'])], array_values($data['articles']));

        $erreurs = [];
        foreach ($articles as $i => $article) {
            $refusees = VariablesContrat::refusees([$article['titre'], $article['texte']], $typeContrat);
            if ($refusees !== []) {
                $erreurs["articles.$i.texte"] = 'Variables non reconnues pour un '.$typeContrat->label().' : '.implode(', ', array_map(fn ($c) => '{{'.$c.'}}', $refusees)).'.';
            }
        }
        $refuseesTitre = VariablesContrat::refusees([$data['titre']], $typeContrat);
        if ($refuseesTitre !== []) {
            $erreurs['titre'] = 'Variables non reconnues : '.implode(', ', array_map(fn ($c) => '{{'.$c.'}}', $refuseesTitre)).'.';
        }
        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        $modeles->enregistrer($request->user()->organization_id, $typeContrat, trim($data['titre']), $articles, $request->user());

        return back()->with('success', 'Modèle '.$typeContrat->label().' enregistré.');
    }
}
