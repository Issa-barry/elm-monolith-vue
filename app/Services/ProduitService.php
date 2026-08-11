<?php

namespace App\Services;

use App\Enums\ProduitType;
use App\Models\Produit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProduitService
{
    /** Champs de produit_variantes portés par le formulaire Produit (variante par défaut/générées). */
    private const CHAMPS_VARIANTE = [
        'code_barres', 'prix_usine', 'prix_vente', 'prix_achat', 'cout', 'seuil_alerte_stock',
    ];

    public function __construct(private VarianteService $varianteService) {}

    /**
     * Crée un produit + sa/ses variante(s) en une transaction. Point d'entrée unique partagé
     * par les controllers Web et API (élimine la duplication de logique constatée avant refonte).
     *
     * $donnees attend les champs produits (nom/type/statut/categorie_id/description), les champs
     * de CHAMPS_VARIANTE, et optionnellement 'options' => [['nom'=>..,'valeurs'=>[..]], ...].
     */
    public function creer(array $donnees): Produit
    {
        $type = ProduitType::from($donnees['type']);
        $donneesVariante = Arr::only($donnees, self::CHAMPS_VARIANTE);
        $this->validerPrixSelonType($type, $donneesVariante);

        return DB::transaction(function () use ($donnees, $donneesVariante) {
            $produit = Produit::create(Arr::except($donnees, [...self::CHAMPS_VARIANTE, 'options']));

            $optionsInput = $donnees['options'] ?? [];
            if (! empty($optionsInput)) {
                $this->varianteService->genererVariantes($produit, $optionsInput, $donneesVariante);
            } else {
                $this->varianteService->creerVarianteParDefaut($produit, $donneesVariante);
            }

            return $produit->fresh(['variantes', 'categorie']);
        });
    }

    /**
     * Met à jour un produit simple (sans déclinaisons) : champs produit + sa variante par défaut.
     * La mise à jour d'un produit à variantes multiples passe par VarianteService (édition
     * individuelle de chaque variante), pas par cette méthode.
     */
    public function mettreAJourSimple(Produit $produit, array $donnees): Produit
    {
        $ancienType = $produit->type;
        $type = ProduitType::from($donnees['type'] ?? $produit->type->value);
        $variante = $produit->variantePrincipale()->first();
        $donneesVariante = Arr::only($donnees, self::CHAMPS_VARIANTE);

        // Valide les prix EFFECTIFS (valeurs déjà sur la variante, écrasées par celles
        // envoyées) — une mise à jour partielle qui ne touche pas au prix ne doit pas être
        // rejetée juste parce que ce champ est absent du payload.
        $donneesEffectives = array_merge(
            $variante ? Arr::only($variante->getAttributes(), self::CHAMPS_VARIANTE) : [],
            $donneesVariante
        );
        $this->validerPrixSelonType($type, $donneesEffectives);

        // Ce formulaire ne touche que la variante principale (cf. docblock ci-dessus). Si le
        // type change réellement, les variantes SECONDAIRES — absentes du payload, donc
        // jamais revalidées par l'appel ci-dessus — doivent quand même rester compatibles
        // avec le nouveau type, sinon le changement est refusé en bloc (avant toute écriture,
        // pour ne jamais laisser le produit dans un état où sa variante principale est déjà
        // au nouveau type mais une variante secondaire ne l'est pas).
        if ($type !== $ancienType) {
            $this->validerCoherenceAutresVariantesPourType($produit, $type, $variante?->id);
        }

        return DB::transaction(function () use ($produit, $donnees, $donneesVariante, $variante) {
            $produit->update(Arr::except($donnees, [...self::CHAMPS_VARIANTE, 'options']));

            if ($variante) {
                $variante->update($donneesVariante);
            } else {
                $this->varianteService->creerVarianteParDefaut($produit, $donneesVariante);
            }

            return $produit->fresh(['variantes', 'categorie']);
        });
    }

    /**
     * Centralise la dette identifiée avant refonte : ProduitType::requiredPrices() existait
     * mais n'était appliqué nulle part côté validation serveur. Un seul point d'entrée pour
     * Web et API — non contournable via l'API. Vérifie la présence des prix requis pour le
     * type, PUIS leur cohérence relationnelle (prix_vente strictement supérieur au coût de
     * référence du type — cf. ProduitType::champPrixReference()) : un produit vendu à perte ou
     * à marge nulle est refusé, jamais silencieusement accepté.
     */
    public function validerPrixSelonType(ProduitType $type, array $donneesPrix): void
    {
        $raison = $this->raisonIncoherencePrix($type, $donneesPrix);

        if ($raison !== null) {
            throw ValidationException::withMessages([$raison['champ'] => $raison['message']]);
        }
    }

    /**
     * Variantes secondaires d'un produit à déclinaisons — jamais présentes dans le payload du
     * formulaire principal (celui-ci n'édite que la variante principale, cf.
     * mettreAJourSimple()) — donc jamais couvertes par le validerPrixSelonType() ci-dessus.
     * Sans ce contrôle, un changement de type via ce formulaire pourrait laisser des variantes
     * secondaires incompatibles avec le nouveau type, invisibles jusqu'à leur prochaine édition
     * individuelle.
     */
    private function validerCoherenceAutresVariantesPourType(Produit $produit, ProduitType $nouveauType, ?string $varianteExclueId): void
    {
        $autresVariantes = $produit->variantes()
            ->when($varianteExclueId, fn ($q) => $q->where('id', '!=', $varianteExclueId))
            ->get();

        foreach ($autresVariantes as $autre) {
            $raison = $this->raisonIncoherencePrix($nouveauType, Arr::only($autre->getAttributes(), self::CHAMPS_VARIANTE));
            if ($raison === null) {
                continue;
            }

            $label = $autre->libelle !== '' ? $autre->libelle : 'variante principale';
            throw ValidationException::withMessages([
                'type' => "Impossible de passer le produit en \"{$nouveauType->label()}\" : la variante « {$label} » n'est pas compatible avec ce type.",
            ]);
        }
    }

    /**
     * @return array{champ: string, message: string}|null null si $donneesPrix est cohérent
     *                                                      avec $type, sinon la première
     *                                                      anomalie trouvée (présence, puis
     *                                                      relation).
     */
    private function raisonIncoherencePrix(ProduitType $type, array $donneesPrix): ?array
    {
        $labels = [
            'prix_usine' => 'prix usine',
            'prix_vente' => 'prix de vente',
            'prix_achat' => "prix d'achat",
        ];

        $manquants = array_filter(
            $type->requiredPrices(),
            fn (string $champ) => ! array_key_exists($champ, $donneesPrix) || $donneesPrix[$champ] === null || $donneesPrix[$champ] === ''
        );

        if (! empty($manquants)) {
            $liste = implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, $manquants));

            return [
                'champ' => 'type',
                'message' => "Pour le type \"{$type->label()}\", les champs suivants sont obligatoires : {$liste}.",
            ];
        }

        $champReference = $type->champPrixReference();
        if ($champReference === null) {
            return null;
        }

        $vente = (float) ($donneesPrix['prix_vente'] ?? 0);
        $reference = (float) ($donneesPrix[$champReference] ?? 0);

        if ($vente <= $reference) {
            return [
                'champ' => 'prix_vente',
                'message' => "Le prix de vente doit être strictement supérieur au {$labels[$champReference]}.",
            ];
        }

        return null;
    }
}
