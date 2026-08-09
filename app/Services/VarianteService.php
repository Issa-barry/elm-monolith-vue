<?php

namespace App\Services;

use App\Models\OptionCatalogue;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitOptionValeur;
use App\Models\ProduitVariante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VarianteService
{
    /**
     * Crée la variante par défaut (invisible côté UX) d'un produit sans déclinaisons —
     * porte SKU/prix/stock, unité transactionnelle unique quel que soit le produit.
     */
    public function creerVarianteParDefaut(Produit $produit, array $donneesVariante = []): ProduitVariante
    {
        return $produit->variantes()->create(array_merge($donneesVariante, [
            'organization_id' => $produit->organization_id,
            'is_default' => true,
            'is_active' => true,
            'combo_hash' => ProduitVariante::COMBO_HASH_DEFAUT,
            'position' => 0,
        ]));
    }

    /**
     * Génère les variantes par produit cartésien des options fournies.
     *
     * @param  array<int, array{nom: string, valeurs: array<int, string>, option_catalogue_id?: ?string}>  $optionsInput
     * @param  array<string, mixed>  $donneesVariante  Prix/codes appliqués à chaque variante générée
     *                                                 (l'utilisateur les ajuste ensuite individuellement).
     * @return Collection<int, ProduitVariante>
     */
    public function genererVariantes(Produit $produit, array $optionsInput, array $donneesVariante = []): Collection
    {
        $orgId = $produit->organization_id;
        $this->verifierLimites($orgId, $optionsInput);

        return DB::transaction(function () use ($produit, $optionsInput, $donneesVariante, $orgId) {
            $options = [];
            foreach (array_values($optionsInput) as $i => $optionInput) {
                $option = $produit->options()->create([
                    'organization_id' => $orgId,
                    'nom' => $optionInput['nom'],
                    'position' => $i,
                ]);

                $valeurs = [];
                foreach (array_values($optionInput['valeurs']) as $j => $valeur) {
                    $valeurs[] = $option->valeurs()->create([
                        'organization_id' => $orgId,
                        'valeur' => $valeur,
                        'position' => $j,
                    ]);
                }

                // Enrichissement à sens unique et purement additif du catalogue réutilisable :
                // les valeurs saisies ici (existantes ou nouvelles) alimentent le catalogue si
                // l'utilisateur a rattaché l'option à une entrée existante, mais aucune écriture
                // ne repart du catalogue vers cette option — cf. brief : modifier "Couleur" dans
                // le catalogue ne doit jamais casser les variantes déjà générées.
                if (! empty($optionInput['option_catalogue_id'])) {
                    $this->enrichirCatalogue($orgId, $optionInput['option_catalogue_id'], array_values($optionInput['valeurs']));
                }

                $options[] = ['option' => $option, 'valeurs' => $valeurs];
            }

            $combinaisons = $this->produitCartesien(array_map(fn ($o) => $o['valeurs'], $options));

            $variantes = collect();
            foreach ($combinaisons as $position => $combo) {
                /** @var array<int, ProduitOptionValeur> $combo */
                $hash = ProduitVariante::calculerComboHash(array_map(fn ($v) => $v->id, $combo));

                $variante = $produit->variantes()->create(array_merge($donneesVariante, [
                    'organization_id' => $orgId,
                    'is_default' => false,
                    'is_active' => true,
                    'combo_hash' => $hash,
                    'position' => $position,
                ]));

                foreach ($combo as $i => $valeur) {
                    $variante->valeurs()->attach($valeur->id, ['produit_option_id' => $options[$i]['option']->id]);
                }

                $variantes->push($variante);
            }

            return $variantes;
        });
    }

    /**
     * Ajoute au catalogue réutilisable les valeurs saisies qui n'y figurent pas encore —
     * append-only, jamais destructif. Échoue silencieusement si l'option de catalogue référencée
     * n'existe pas ou appartient à une autre organisation (défensif : ne doit jamais bloquer la
     * génération des variantes, le catalogue n'étant qu'une bibliothèque de suggestions).
     *
     * @param  array<int, string>  $valeurs
     */
    private function enrichirCatalogue(string $orgId, string $optionCatalogueId, array $valeurs): void
    {
        $optionCatalogue = OptionCatalogue::where('organization_id', $orgId)->find($optionCatalogueId);
        if (! $optionCatalogue) {
            return;
        }

        $existantes = $optionCatalogue->valeurs()->pluck('valeur')
            ->map(fn ($v) => Str::lower(trim($v)))
            ->all();

        $position = $optionCatalogue->valeurs()->max('position');
        $position = $position === null ? 0 : $position + 1;

        foreach ($valeurs as $valeur) {
            if (in_array(Str::lower(trim($valeur)), $existantes, true)) {
                continue;
            }

            $optionCatalogue->valeurs()->create(['valeur' => $valeur, 'position' => $position]);
            $existantes[] = Str::lower(trim($valeur));
            $position++;
        }
    }

    /**
     * Vérifie les limites catalogue de l'organisation AVANT toute écriture — message explicite
     * incluant le nombre de variantes que la configuration soumise générerait.
     *
     * @param  array<int, array{nom: string, valeurs: array<int, string>}>  $optionsInput
     */
    public function verifierLimites(string $orgId, array $optionsInput): void
    {
        $maxOptions = Parametre::getMaxOptionsProduit($orgId);
        if (count($optionsInput) > $maxOptions) {
            throw ValidationException::withMessages([
                'options' => "Vous ne pouvez pas définir plus de {$maxOptions} options par produit (limite de votre organisation).",
            ]);
        }

        $maxValeurs = Parametre::getMaxValeursOption($orgId);
        foreach ($optionsInput as $optionInput) {
            $nb = count($optionInput['valeurs']);
            if ($nb > $maxValeurs) {
                throw ValidationException::withMessages([
                    'options' => "L'option \"{$optionInput['nom']}\" ne peut pas avoir plus de {$maxValeurs} valeurs (limite de votre organisation).",
                ]);
            }
        }

        $maxVariantes = Parametre::getMaxVariantesProduit($orgId);
        $total = array_product(array_map(fn ($o) => max(count($o['valeurs']), 1), $optionsInput)) ?: 0;
        if ($total > $maxVariantes) {
            throw ValidationException::withMessages([
                'options' => "Cette configuration générerait {$total} variantes. La limite configurée pour votre organisation est de {$maxVariantes}.",
            ]);
        }
    }

    /**
     * Produit cartésien de N listes de ProduitOptionValeur, une par option, dans l'ordre des options.
     *
     * @param  array<int, array<int, ProduitOptionValeur>>  $listes
     * @return array<int, array<int, ProduitOptionValeur>>
     */
    private function produitCartesien(array $listes): array
    {
        return array_reduce($listes, function (array $carry, array $liste) {
            $resultat = [];
            foreach ($carry as $comboPartiel) {
                foreach ($liste as $item) {
                    $resultat[] = [...$comboPartiel, $item];
                }
            }

            return $resultat;
        }, [[]]);
    }
}
