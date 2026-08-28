<?php

namespace App\Services;

use App\Models\Produit;
use App\Models\ProduitType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProduitService
{
    /** Code stable du type de produit pour lequel la tarification par nature de client existe. */
    private const TYPE_CODE_FABRICABLE = 'fabricable';

    /** Champs de produit_variantes portés par le formulaire Produit (variante par défaut/générées). */
    private const CHAMPS_VARIANTE = [
        'code_barres', 'prix_usine', 'prix_usine_tricycle',
        'prix_externe', 'prix_revendeur', 'prix_distributeur',
        'prix_vente', 'prix_achat', 'cout',
    ];

    /** Sous-ensemble de CHAMPS_VARIANTE réservé aux produits fabricables (cf. PrixVenteNatureResolver). */
    private const CHAMPS_PRIX_NATURE = ['prix_externe', 'prix_revendeur', 'prix_distributeur'];

    /**
     * CHAMPS_VARIANTE + 'sku', utilisé UNIQUEMENT par creer() : un SKU fourni explicitement (ex.
     * import Excel préparant un futur réimport de mise à jour, cf. ImportProduitsParser) doit
     * être respecté tel quel à la CRÉATION — ProduitVariante::booted() ne l'auto-génère que si
     * vide. Ne JAMAIS réutiliser cette constante dans mettreAJourSimple() : le SKU d'une variante
     * existante n'est jamais réécrit par une mise à jour, quel que soit l'appelant.
     */
    private const CHAMPS_VARIANTE_CREATION = [...self::CHAMPS_VARIANTE, 'sku'];

    public function __construct(private VarianteService $varianteService) {}

    /**
     * Crée un produit + sa/ses variante(s) en une transaction. Point d'entrée unique partagé
     * par les controllers Web et API (élimine la duplication de logique constatée avant refonte).
     *
     * $donnees attend les champs produits (nom/produit_type_id/statut/categorie_id/description,
     * seuil_alerte_stock/alerte_stock_active), les champs de CHAMPS_VARIANTE_CREATION, et
     * optionnellement 'options' => [['nom'=>..,'valeurs'=>[..]], ...].
     */
    public function creer(array $donnees): Produit
    {
        $type = ProduitType::findOrFail($donnees['produit_type_id']);
        $donneesVariante = $this->nettoyerPrixNatureSiNonFabricable(
            $type,
            Arr::only($donnees, self::CHAMPS_VARIANTE_CREATION)
        );
        $this->validerPrixSelonType($type, $donneesVariante);

        // Un SKU explicite n'a de sens que pour la variante par défaut d'un produit simple : les
        // variantes générées par options partageraient toutes le même SKU (violerait unique(
        // organization_id, sku)).
        if (! empty($donneesVariante['sku']) && ! empty($donnees['options'])) {
            throw ValidationException::withMessages([
                'sku' => 'Un SKU explicite ne peut être fourni que pour un produit sans déclinaisons.',
            ]);
        }

        return DB::transaction(function () use ($donnees, $donneesVariante) {
            $produit = Produit::create(Arr::except($donnees, [...self::CHAMPS_VARIANTE_CREATION, 'options']));

            $optionsInput = $donnees['options'] ?? [];
            if (! empty($optionsInput)) {
                $this->varianteService->genererVariantes($produit, $optionsInput, $donneesVariante);
            } else {
                $this->varianteService->creerVarianteParDefaut($produit, $donneesVariante);
            }

            return $produit->fresh(['variantes', 'categorie', 'produitType']);
        });
    }

    /**
     * Met à jour un produit simple (sans déclinaisons) : champs produit + sa variante par défaut.
     * La mise à jour d'un produit à variantes multiples passe par VarianteService (édition
     * individuelle de chaque variante), pas par cette méthode.
     */
    public function mettreAJourSimple(Produit $produit, array $donnees): Produit
    {
        $ancienTypeId = $produit->produit_type_id;
        $type = ProduitType::findOrFail($donnees['produit_type_id'] ?? $ancienTypeId);
        $variante = $produit->variantePrincipale()->first();
        $donneesVariante = $this->nettoyerPrixNatureSiNonFabricable(
            $type,
            Arr::only($donnees, self::CHAMPS_VARIANTE)
        );

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
        if ($type->id !== $ancienTypeId) {
            $this->validerCoherenceAutresVariantesPourType($produit, $type, $variante?->id);
        }

        return DB::transaction(function () use ($produit, $donnees, $donneesVariante, $variante) {
            $produit->update(Arr::except($donnees, [...self::CHAMPS_VARIANTE, 'options']));

            if ($variante) {
                $variante->update($donneesVariante);
            } else {
                $this->varianteService->creerVarianteParDefaut($produit, $donneesVariante);
            }

            return $produit->fresh(['variantes', 'categorie', 'produitType']);
        });
    }

    /**
     * Centralise la dette identifiée avant refonte : ProduitType::requiredPrices() existait
     * (dans l'ancien enum) mais n'était appliqué nulle part côté validation serveur. Un seul
     * point d'entrée pour Web et API — non contournable via l'API. Vérifie la présence des prix
     * requis pour le type, PUIS leur cohérence relationnelle (prix_vente strictement supérieur
     * au coût de référence du type — cf. ProduitType::champPrixReference()) : un produit vendu
     * à perte ou à marge nulle est refusé, jamais silencieusement accepté. Ce garde-fou reste
     * imposé par le système même si les capacités du type sont désormais éditables par
     * l'organisation (cf. ProduitTypeController) — l'admin choisit la configuration du type,
     * jamais la désactivation de la règle de marge qui en découle.
     */
    /**
     * Les tarifs par nature de client (prix_externe/prix_revendeur/prix_distributeur, cf.
     * PrixVenteNatureResolver) n'ont de sens que pour les produits fabricables — toute valeur
     * soumise pour un autre type est ignorée plutôt que silencieusement persistée, pour ne
     * jamais laisser une variante non-fabricable porter un tarif mort qu'aucun code ne consulte.
     */
    private function nettoyerPrixNatureSiNonFabricable(ProduitType $type, array $donneesVariante): array
    {
        if ($type->code === self::TYPE_CODE_FABRICABLE) {
            return $donneesVariante;
        }

        foreach (self::CHAMPS_PRIX_NATURE as $champ) {
            if (array_key_exists($champ, $donneesVariante)) {
                $donneesVariante[$champ] = null;
            }
        }

        return $donneesVariante;
    }

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
                'produit_type_id' => "Impossible de passer le produit en « {$nouveauType->nom} » : la variante « {$label} » n'est pas compatible avec ce type.",
            ]);
        }
    }

    /**
     * @return array{champ: string, message: string}|null null si $donneesPrix est cohérent
     *                                                    avec $type, sinon la première
     *                                                    anomalie trouvée (présence, puis
     *                                                    relation).
     */
    private function raisonIncoherencePrix(ProduitType $type, array $donneesPrix): ?array
    {
        $labels = [
            'prix_usine' => 'prix usine',
            'prix_usine_tricycle' => 'prix usine tricycle',
            'prix_vente' => 'prix de vente',
            'prix_achat' => "prix d'achat",
            'prix_externe' => 'prix externe',
            'prix_revendeur' => 'prix revendeur',
            'prix_distributeur' => 'prix distributeur',
        ];

        // Les tarifs par nature de client sont obligatoires pour un produit fabricable — au même
        // titre que prix_usine/prix_usine_tricycle/prix_vente — et non configurables par type
        // (contrairement à requiredPrices()) : ils n'ont de sens que pour ce code stable précis.
        $champsRequis = $type->code === self::TYPE_CODE_FABRICABLE
            ? [...$type->requiredPrices(), ...self::CHAMPS_PRIX_NATURE]
            : $type->requiredPrices();

        $manquants = array_filter(
            $champsRequis,
            fn (string $champ) => ! array_key_exists($champ, $donneesPrix) || $donneesPrix[$champ] === null || $donneesPrix[$champ] === ''
        );

        if (! empty($manquants)) {
            $liste = implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, $manquants));

            return [
                'champ' => 'produit_type_id',
                'message' => "Pour le type « {$type->nom} », les champs suivants sont obligatoires : {$liste}.",
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

        // Tarif tricycle : contrôlé indépendamment du tarif "autres véhicules" ci-dessus — une
        // marge correcte sur l'un ne doit jamais masquer une marge nulle/négative sur l'autre.
        // Uniquement pertinent quand prix_usine est le champ de référence du type ET que la
        // variante définit effectivement un tarif tricycle (jamais forcé sur les types où
        // prix_usine lui-même n'a pas de sens, cf. ProduitType::champPrixReference()).
        if ($champReference === 'prix_usine') {
            $tricycle = $donneesPrix['prix_usine_tricycle'] ?? null;
            if ($tricycle !== null && $tricycle !== '' && $vente <= (float) $tricycle) {
                return [
                    'champ' => 'prix_vente',
                    'message' => 'Le prix de vente doit être strictement supérieur au prix usine tricycle.',
                ];
            }
        }

        return null;
    }
}
