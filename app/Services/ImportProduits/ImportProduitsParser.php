<?php

namespace App\Services\ImportProduits;

use App\Enums\ProduitStatut;
use App\Enums\StatutImportProduits;
use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\ImportProduits;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\ProduitVariante;
use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use App\Services\ProduitService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lit et classe un fichier d'import "produits" — une feuille (`PRODUITS`), une ligne par
 * produit simple (variante par défaut uniquement, cf. brief : produits à variantes hors
 * périmètre V1). Ne modifie jamais la base : composant de lecture seule, appelé à la fois pour
 * l'aperçu et juste avant l'exécution réelle (ImportProduitsExecutor), pour ne jamais laisser
 * d'écart entre ce qui a été prévisualisé et ce qui est enregistré — même principe
 * qu'ImportFlotteParser/SiteImportParser.
 *
 * Identification : le SKU de la variante par défaut, unique par organisation, jamais le nom
 * (cf. ReferenceValueResolver::normalizeCodeKey() pour la comparaison). SKU vide => création
 * (SKU auto-généré par ProduitVariante::booted()) ; SKU renseigné et trouvé sur une variante
 * par défaut d'un produit à variante UNIQUE, non archivée => mise à jour ; toute autre
 * correspondance (variante secondaire, produit à variantes multiples, variante archivée) =>
 * erreur bloquante explicite, jamais un rapprochement silencieux. SKU renseigné mais
 * introuvable => création avec ce SKU explicite.
 *
 * Convention à 3 états sur les champs facultatifs (categorie_reference, fournisseur_reference,
 * code_barres, prix_*, cout, seuil_alerte_stock, description) : cellule vide = conserver la
 * valeur existante (update) / valeur par défaut null (création) ; valeur renseignée =
 * remplacer ; `#VIDER#` = effacer explicitement (refusé à la création — aucun état antérieur —
 * et refusé sur tout champ obligatoire).
 */
class ImportProduitsParser
{
    /**
     * Volumétrie réelle attendue : quelques dizaines à centaines de lignes — 500 laisse une
     * bonne marge (même plafond qu'ImportFlotteParser).
     */
    private const MAX_LIGNES = 500;

    private const SENTINEL_VIDER = '#VIDER#';

    private const CHAMPS_PRIX = ['prix_achat', 'prix_usine', 'prix_usine_tricycle', 'prix_vente', 'cout'];

    /**
     * Nom explicite exposé dans les nouveaux classeurs. Le modèle métier et la base conservent
     * volontairement `prix_usine`; l'alias historique reste accepté à l'import pour que les
     * modèles déjà téléchargés avant ce renommage continuent de fonctionner.
     */
    private const COLONNE_PRIX_USINE_AUTRES_VEHICULES = 'prix_usine_autres_vehicules';

    public function __construct(private readonly ProduitService $produitService) {}

    public function analyserFichier(string $absolutePath, string $orgId): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $lignes = $this->lireFeuille($spreadsheet);
        $hash = hash_file('sha256', $absolutePath) ?: null;

        return $this->analyser($lignes, $orgId, $hash);
    }

    public function analyser(Collection $lignes, string $orgId, ?string $fichierHash = null): array
    {
        if ($lignes->isEmpty()) {
            return ['nb_lignes_total' => 0, 'lignes' => [], 'fichier_deja_importe' => null];
        }

        if ($lignes->count() > self::MAX_LIGNES) {
            return [
                'nb_lignes_total' => $lignes->count(),
                'lignes' => [$this->erreur(null, null, [sprintf(
                    'Le fichier contient trop de lignes (%d), maximum autorisé : %d. Scindez-le en plusieurs imports.',
                    $lignes->count(),
                    self::MAX_LIGNES
                )])],
                'fichier_deja_importe' => null,
            ];
        }

        // ── Préchargement (une fois par appel, avant la boucle) ─────────────────────
        $toutesVariantes = ProduitVariante::where('organization_id', $orgId)->with('produit.produitType')->get();
        $variantesParSku = $this->indexerParSku($toutesVariantes);
        $varianteCountParProduit = $toutesVariantes->groupBy('produit_id')->map->count();

        $variantesArchiveesParSku = $this->indexerParSku(
            ProduitVariante::onlyTrashed()->where('organization_id', $orgId)->get()
        );

        // code_barres => variante_id, pour détecter un conflit d'unicité sans le laisser à la
        // contrainte DB brute (cf. ProduitController::validerFormulaire()).
        $codesBarresExistants = ProduitVariante::where('organization_id', $orgId)
            ->whereNotNull('code_barres')
            ->pluck('id', 'code_barres');

        $typesActifs = ProduitType::where('organization_id', $orgId)->where('statut', 'actif')->get();
        // Pas de filtre "actif" sur les catégories : Categorie::validerFormulaire() côté
        // ProduitController n'impose pas non plus cette contrainte (cf. étude préalable).
        $categories = Categorie::where('organization_id', $orgId)->get();
        // Fournisseurs actifs uniquement : cohérent avec l'onglet REFERENCES du modèle et
        // Fournisseur::scopeActifs(), déjà utilisé pour peupler le sélecteur du formulaire Web.
        $fournisseurs = Fournisseur::where('organization_id', $orgId)->actifs()->get();

        $premiereLigneParSku = [];
        $premiereLigneParCodeBarres = [];
        $resultats = [];
        $auMoinsUneCreationSansSku = false;

        foreach ($lignes as $index => $ligne) {
            $numeroLigne = $index + 2;
            [$resultat, $creationSansSku] = $this->analyserLigne(
                $ligne,
                $numeroLigne,
                $orgId,
                $variantesParSku,
                $variantesArchiveesParSku,
                $varianteCountParProduit,
                $typesActifs,
                $categories,
                $fournisseurs,
                $codesBarresExistants,
                $premiereLigneParSku,
                $premiereLigneParCodeBarres,
            );
            $auMoinsUneCreationSansSku = $auMoinsUneCreationSansSku || $creationSansSku;
            $resultats[] = $resultat;
        }

        $fichierDejaImporte = null;
        if ($fichierHash !== null && $auMoinsUneCreationSansSku) {
            $importPrecedent = ImportProduits::where('organization_id', $orgId)
                ->where('statut', StatutImportProduits::TERMINE->value)
                ->where('fichier_hash', $fichierHash)
                ->orderByDesc('termine_le')
                ->first();

            if ($importPrecedent) {
                $fichierDejaImporte = [
                    'import_id' => $importPrecedent->id,
                    'termine_le' => $importPrecedent->termine_le?->toISOString(),
                ];
            }
        }

        return [
            'nb_lignes_total' => $lignes->count(),
            'lignes' => $resultats,
            'fichier_deja_importe' => $fichierDejaImporte,
        ];
    }

    /**
     * @return array{0: array, 1: bool} [résultat de ligne, "est une création dont le SKU était vide"]
     */
    private function analyserLigne(
        Collection $ligne,
        int $numeroLigne,
        string $orgId,
        Collection $variantesParSku,
        Collection $variantesArchiveesParSku,
        Collection $varianteCountParProduit,
        Collection $typesActifs,
        Collection $categories,
        Collection $fournisseurs,
        Collection $codesBarresExistants,
        array &$premiereLigneParSku,
        array &$premiereLigneParCodeBarres,
    ): array {
        $erreurs = [];
        $avertissements = [];
        $normalisations = [];

        // ── SKU : identification (jamais le nom, cf. docblock de la classe) ─────────
        $skuSaisi = $this->cellule($ligne, 'sku');
        $skuNormalise = $skuSaisi !== '' ? self::normaliserSku($skuSaisi) : null;
        $skuFinal = $skuSaisi !== '' ? $skuSaisi : null;

        /** @var Produit|null $produit */
        $produit = null;
        /** @var ProduitVariante|null $variante */
        $variante = null;
        $estCreation = true;

        if ($skuNormalise !== null) {
            if (isset($premiereLigneParSku[$skuNormalise])) {
                $erreurs[] = "Ligne {$numeroLigne} — `sku` : « {$skuSaisi} » apparaît déjà ligne {$premiereLigneParSku[$skuNormalise]} de ce fichier.";

                return [$this->erreur($numeroLigne, $skuSaisi, $erreurs), false];
            }
            $premiereLigneParSku[$skuNormalise] = $numeroLigne;

            $varianteExistante = $variantesParSku->get($skuNormalise);
            if ($varianteExistante) {
                $produitExistant = $varianteExistante->produit;

                if (! $varianteExistante->is_default || $varianteExistante->combo_hash !== ProduitVariante::COMBO_HASH_DEFAUT) {
                    $erreurs[] = "Ligne {$numeroLigne} — `sku` : « {$skuSaisi} » correspond à une déclinaison (variante non par défaut d'un produit à variantes) — hors périmètre de cet import.";

                    return [$this->erreur($numeroLigne, $skuSaisi, $erreurs), false];
                }
                if (! $produitExistant || $produitExistant->trashed()) {
                    $erreurs[] = "Ligne {$numeroLigne} — `sku` : « {$skuSaisi} » correspond à un produit supprimé.";

                    return [$this->erreur($numeroLigne, $skuSaisi, $erreurs), false];
                }
                if (($varianteCountParProduit->get($produitExistant->id) ?? 0) > 1) {
                    $erreurs[] = "Ligne {$numeroLigne} — `sku` : « {$skuSaisi} » appartient à un produit qui possède plusieurs variantes — hors périmètre de cet import.";

                    return [$this->erreur($numeroLigne, $skuSaisi, $erreurs), false];
                }

                $produit = $produitExistant;
                $variante = $varianteExistante;
                $estCreation = false;
            } elseif ($variantesArchiveesParSku->has($skuNormalise)) {
                $erreurs[] = "Ligne {$numeroLigne} — `sku` : « {$skuSaisi} » correspond à une variante archivée (supprimée) — ne peut pas être réutilisé automatiquement par import.";

                return [$this->erreur($numeroLigne, $skuSaisi, $erreurs), false];
            } else {
                $avertissements[] = "Aucun produit existant ne porte le SKU « {$skuSaisi} » : un nouveau produit sera créé avec ce SKU.";
            }
        }

        $donnees = [];
        $changements = [];

        // ── nom (obligatoire, jamais #VIDER#) ───────────────────────────────────────
        $nomBrut = $this->cellule($ligne, 'nom');
        if ($this->estVider($nomBrut)) {
            $erreurs[] = "Ligne {$numeroLigne} — `nom` : #VIDER# n'est pas autorisé sur un champ obligatoire.";
        } elseif ($estCreation) {
            if ($nomBrut === '') {
                $erreurs[] = "Ligne {$numeroLigne} — `nom` : obligatoire à la création.";
            } else {
                $donnees['nom'] = $nomBrut;
            }
        } elseif ($nomBrut !== '') {
            $donnees['nom'] = $nomBrut;
            if ($this->differe($nomBrut, $produit->nom)) {
                $changements['nom'] = ['avant' => $produit->nom, 'apres' => $nomBrut];
            }
        }

        // ── type_code (obligatoire à la création ; modifiable en mise à jour, avec les
        // mêmes contrôles que ProduitService::mettreAJourSimple() — jamais une donnée
        // renseignée silencieusement ignorée) ───────────────────────────────────────
        $typeBrut = $this->cellule($ligne, 'type_code');
        $type = null;
        if ($estCreation) {
            if ($this->estVider($typeBrut) || $typeBrut === '') {
                $erreurs[] = "Ligne {$numeroLigne} — `type_code` : obligatoire à la création.";
            } else {
                $type = ReferenceValueResolver::matchExact($typeBrut, $typesActifs, fn (ProduitType $t) => $t->code);
                if ($type) {
                    $donnees['produit_type_id'] = $type->id;
                } else {
                    $suggestion = ReferenceValueResolver::suggestClosest($typeBrut, $typesActifs, fn (ProduitType $t) => $t->code);
                    $erreurs[] = $suggestion
                        ? "Ligne {$numeroLigne} — `type_code` : \"{$typeBrut}\" introuvable. Vouliez-vous dire \"{$suggestion}\" ?"
                        : "Ligne {$numeroLigne} — `type_code` : \"{$typeBrut}\" introuvable parmi les types actifs de l'organisation.";
                }
            }
        } else {
            $type = $produit->produitType;
            if ($this->estVider($typeBrut)) {
                $erreurs[] = "Ligne {$numeroLigne} — `type_code` : #VIDER# n'est pas autorisé.";
            } elseif ($typeBrut !== '') {
                $candidat = ReferenceValueResolver::matchExact($typeBrut, $typesActifs, fn (ProduitType $t) => $t->code);
                if (! $candidat) {
                    $suggestion = ReferenceValueResolver::suggestClosest($typeBrut, $typesActifs, fn (ProduitType $t) => $t->code);
                    $erreurs[] = $suggestion
                        ? "Ligne {$numeroLigne} — `type_code` : \"{$typeBrut}\" introuvable. Vouliez-vous dire \"{$suggestion}\" ?"
                        : "Ligne {$numeroLigne} — `type_code` : \"{$typeBrut}\" introuvable parmi les types actifs de l'organisation.";
                } elseif ($candidat->id !== $produit->produit_type_id) {
                    $donnees['produit_type_id'] = $candidat->id;
                    $type = $candidat;
                    $changements['type'] = ['avant' => $produit->produitType?->code, 'apres' => $candidat->code];
                }
            }
        }

        // ── statut (obligatoire, jamais #VIDER#) ────────────────────────────────────
        $statutBrut = $this->cellule($ligne, 'statut');
        if ($this->estVider($statutBrut)) {
            $erreurs[] = "Ligne {$numeroLigne} — `statut` : #VIDER# n'est pas autorisé sur un champ obligatoire.";
        } elseif ($estCreation) {
            if ($statutBrut === '') {
                $erreurs[] = "Ligne {$numeroLigne} — `statut` : obligatoire à la création.";
            } else {
                $statut = $this->resoudreStatut($statutBrut);
                if ($statut === null) {
                    $erreurs[] = "Ligne {$numeroLigne} — `statut` : \"{$statutBrut}\" invalide (valeurs autorisées : ".implode(', ', ProduitStatut::values()).').';
                } else {
                    $donnees['statut'] = $statut->value;
                }
            }
        } elseif ($statutBrut !== '') {
            $statut = $this->resoudreStatut($statutBrut);
            if ($statut === null) {
                $erreurs[] = "Ligne {$numeroLigne} — `statut` : \"{$statutBrut}\" invalide (valeurs autorisées : ".implode(', ', ProduitStatut::values()).').';
            } else {
                $donnees['statut'] = $statut->value;
                if ($statut->value !== $produit->statut->value) {
                    $changements['statut'] = ['avant' => $produit->statut->label(), 'apres' => $statut->label()];
                }
            }
        }

        // ── categorie_reference (facultatif, #VIDER# autorisé) ──────────────────────
        $this->resoudreReference(
            $ligne, 'categorie_reference', $numeroLigne, $estCreation, $categories,
            fn (Categorie $c) => $c->reference, fn (Categorie $c) => $c->nom,
            'categorie_id', 'categorie', $produit?->categorie_id, $produit?->categorie?->reference,
            $donnees, $changements, $erreurs,
        );

        // ── fournisseur_reference (facultatif, #VIDER# autorisé) ────────────────────
        $this->resoudreReference(
            $ligne, 'fournisseur_reference', $numeroLigne, $estCreation, $fournisseurs,
            fn (Fournisseur $f) => $f->reference, fn (Fournisseur $f) => $f->nom_complet ?? $f->reference,
            'fournisseur_id', 'fournisseur', $produit?->fournisseur_id, $produit?->fournisseur?->reference,
            $donnees, $changements, $erreurs,
        );

        // ── code_barres (facultatif, #VIDER# autorisé, unicité pré-vérifiée) ────────
        $codeBarresBrut = $this->cellule($ligne, 'code_barres');
        if ($this->estVider($codeBarresBrut)) {
            if ($estCreation) {
                $erreurs[] = "Ligne {$numeroLigne} — `code_barres` : #VIDER# n'est pas autorisé à la création.";
            } else {
                $donnees['code_barres'] = null;
                if ($variante->code_barres !== null) {
                    $changements['code_barres'] = ['avant' => $variante->code_barres, 'apres' => null];
                }
            }
        } elseif ($codeBarresBrut !== '') {
            if (mb_strlen($codeBarresBrut) > 100) {
                $erreurs[] = "Ligne {$numeroLigne} — `code_barres` : ne peut pas dépasser 100 caractères.";
            } elseif (isset($premiereLigneParCodeBarres[$codeBarresBrut]) && $premiereLigneParCodeBarres[$codeBarresBrut] !== $numeroLigne) {
                $erreurs[] = "Ligne {$numeroLigne} — `code_barres` : « {$codeBarresBrut} » apparaît déjà ligne {$premiereLigneParCodeBarres[$codeBarresBrut]} de ce fichier.";
            } else {
                $premiereLigneParCodeBarres[$codeBarresBrut] = $numeroLigne;
                $varianteProprietaire = $codesBarresExistants->get($codeBarresBrut);
                $conflit = $varianteProprietaire !== null && (! $variante || $varianteProprietaire !== $variante->id);
                if ($conflit) {
                    $erreurs[] = "Ligne {$numeroLigne} — `code_barres` : « {$codeBarresBrut} » est déjà utilisé par un autre produit de cette organisation.";
                } else {
                    $donnees['code_barres'] = $codeBarresBrut;
                    if (! $estCreation && $this->differe($codeBarresBrut, $variante->code_barres)) {
                        $changements['code_barres'] = ['avant' => $variante->code_barres, 'apres' => $codeBarresBrut];
                    }
                }
            }
        }

        // ── prix (facultatifs par cellule, #VIDER# autorisé — cf. colonnes nullable) ─
        $prixUsineAutresVehicules = $this->celluleBrute($ligne, self::COLONNE_PRIX_USINE_AUTRES_VEHICULES);
        $prixUsineHistorique = $this->celluleBrute($ligne, 'prix_usine');
        if ($prixUsineAutresVehicules !== '' && $prixUsineHistorique !== '' && $prixUsineAutresVehicules !== $prixUsineHistorique) {
            $erreurs[] = "Ligne {$numeroLigne} — `prix_usine_autres_vehicules` : le fichier contient aussi l'ancienne colonne `prix_usine` avec une valeur différente. Conservez une seule valeur pour éviter toute ambiguïté.";
        }

        foreach (self::CHAMPS_PRIX as $champ) {
            $brut = $this->cellule($ligne, $champ);
            $colonneImport = $champ === 'prix_usine' ? self::COLONNE_PRIX_USINE_AUTRES_VEHICULES : $champ;
            if ($this->estVider($brut)) {
                if ($estCreation) {
                    $erreurs[] = "Ligne {$numeroLigne} — `{$colonneImport}` : #VIDER# n'est pas autorisé à la création.";
                } else {
                    $donnees[$champ] = null;
                    $ancien = $variante->{$champ};
                    if ($ancien !== null) {
                        $changements[$champ] = ['avant' => $ancien, 'apres' => null];
                    }
                }

                continue;
            }
            if ($brut === '') {
                continue;
            }
            if (! preg_match('/^\d+$/', $brut) || (int) $brut < 0) {
                $erreurs[] = "Ligne {$numeroLigne} — `{$colonneImport}` : \"{$brut}\" invalide (entier positif ou nul attendu).";

                continue;
            }
            $valeur = (int) $brut;
            $donnees[$champ] = $valeur;
            if (! $estCreation) {
                $ancien = $variante->{$champ};
                if ($this->differe($valeur, $ancien)) {
                    $changements[$champ] = ['avant' => $ancien, 'apres' => $valeur];
                }
            }
        }

        // ── alerte_stock_active (obligatoire, oui/non, jamais #VIDER#) ──────────────
        $alerteBrut = $this->cellule($ligne, 'alerte_stock_active');
        if ($this->estVider($alerteBrut)) {
            $erreurs[] = "Ligne {$numeroLigne} — `alerte_stock_active` : #VIDER# n'est pas autorisé sur un champ obligatoire.";
        } elseif ($estCreation) {
            if ($alerteBrut === '') {
                $erreurs[] = "Ligne {$numeroLigne} — `alerte_stock_active` : obligatoire à la création (oui/non).";
            } else {
                $bool = $this->resoudreBool($alerteBrut);
                if ($bool === null) {
                    $erreurs[] = "Ligne {$numeroLigne} — `alerte_stock_active` : \"{$alerteBrut}\" invalide (oui/non attendu).";
                } else {
                    $donnees['alerte_stock_active'] = $bool;
                }
            }
        } elseif ($alerteBrut !== '') {
            $bool = $this->resoudreBool($alerteBrut);
            if ($bool === null) {
                $erreurs[] = "Ligne {$numeroLigne} — `alerte_stock_active` : \"{$alerteBrut}\" invalide (oui/non attendu).";
            } else {
                $donnees['alerte_stock_active'] = $bool;
                if ($bool !== (bool) $produit->alerte_stock_active) {
                    $changements['alerte_stock_active'] = ['avant' => $produit->alerte_stock_active ? 'Oui' : 'Non', 'apres' => $bool ? 'Oui' : 'Non'];
                }
            }
        }

        // ── seuil_alerte_stock (facultatif, #VIDER# = repli sur le défaut org) ──────
        $seuilBrut = $this->cellule($ligne, 'seuil_alerte_stock');
        if ($this->estVider($seuilBrut)) {
            if ($estCreation) {
                $erreurs[] = "Ligne {$numeroLigne} — `seuil_alerte_stock` : #VIDER# n'est pas autorisé à la création.";
            } else {
                $donnees['seuil_alerte_stock'] = null;
                if ($produit->seuil_alerte_stock !== null) {
                    $changements['seuil_alerte_stock'] = ['avant' => $produit->seuil_alerte_stock, 'apres' => 'Défaut organisation'];
                }
            }
        } elseif ($seuilBrut !== '') {
            if (! preg_match('/^\d+$/', $seuilBrut) || (int) $seuilBrut < 1) {
                $erreurs[] = "Ligne {$numeroLigne} — `seuil_alerte_stock` : \"{$seuilBrut}\" invalide (entier ≥ 1 attendu).";
            } else {
                $valeur = (int) $seuilBrut;
                $donnees['seuil_alerte_stock'] = $valeur;
                if (! $estCreation && $this->differe($valeur, $produit->seuil_alerte_stock)) {
                    $changements['seuil_alerte_stock'] = ['avant' => $produit->seuil_alerte_stock, 'apres' => $valeur];
                }
            }
        }

        // ── description (facultatif, #VIDER# autorisé) ──────────────────────────────
        $descriptionBrut = $this->cellule($ligne, 'description');
        if ($this->estVider($descriptionBrut)) {
            if ($estCreation) {
                $erreurs[] = "Ligne {$numeroLigne} — `description` : #VIDER# n'est pas autorisé à la création.";
            } else {
                $donnees['description'] = null;
                if ($produit->description !== null) {
                    $changements['description'] = ['avant' => $produit->description, 'apres' => null];
                }
            }
        } elseif ($descriptionBrut !== '') {
            $donnees['description'] = $descriptionBrut;
            if (! $estCreation && $this->differe($descriptionBrut, $produit->description)) {
                $changements['description'] = ['avant' => $produit->description, 'apres' => $descriptionBrut];
            }
        }

        if (! empty($erreurs)) {
            return [$this->erreur($numeroLigne, $skuSaisi !== '' ? $skuSaisi : null, $erreurs, $avertissements, $normalisations), false];
        }

        // ── Validation prix via le service métier (source de vérité unique) ─────────
        if ($type) {
            $donneesPrixEffectives = $estCreation
                ? $donnees
                : array_merge(
                    array_intersect_key(['prix_usine' => $variante->prix_usine, 'prix_usine_tricycle' => $variante->prix_usine_tricycle, 'prix_vente' => $variante->prix_vente, 'prix_achat' => $variante->prix_achat], array_flip(self::CHAMPS_PRIX)),
                    array_intersect_key($donnees, array_flip(self::CHAMPS_PRIX)),
                );
            try {
                $this->produitService->validerPrixSelonType($type, $donneesPrixEffectives);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    foreach ($messages as $message) {
                        $erreurs[] = "Ligne {$numeroLigne} — {$message}";
                    }
                }
            }
        }

        if (! empty($erreurs)) {
            return [$this->erreur($numeroLigne, $skuSaisi !== '' ? $skuSaisi : null, $erreurs, $avertissements, $normalisations), false];
        }

        if ($estCreation) {
            $donnees['sku'] = $skuFinal;

            return [[
                'numero_ligne' => $numeroLigne,
                'sku' => $skuFinal ?? 'généré automatiquement',
                'nom' => $donnees['nom'] ?? null,
                'statut' => 'creation',
                'erreurs' => [],
                'avertissements' => $avertissements,
                'normalisations' => $normalisations,
                'changements' => [],
                'data' => $donnees,
            ], $skuFinal === null];
        }

        $statutLigne = empty($changements) ? 'inchange' : 'mise_a_jour';

        // $changements est indexé par libellé d'affichage ("categorie"/"fournisseur"), pas par
        // la clé réellement transmise au service ("categorie_id"/"fournisseur_id") — la
        // correspondance est explicite ici plutôt qu'un array_intersect_key naïf, qui omettrait
        // silencieusement ces deux champs du payload d'écriture tout en les affichant comme
        // "changés" dans l'aperçu (l'UI mentirait sur ce qui a réellement été enregistré).
        $champLabelVersCleDonnee = ['categorie' => 'categorie_id', 'fournisseur' => 'fournisseur_id', 'type' => 'produit_type_id'];
        $clesAEcrire = array_map(fn (string $label) => $champLabelVersCleDonnee[$label] ?? $label, array_keys($changements));

        return [[
            'numero_ligne' => $numeroLigne,
            'sku' => $skuFinal,
            'nom' => $produit->nom,
            'statut' => $statutLigne,
            'erreurs' => [],
            'avertissements' => $avertissements,
            'normalisations' => $normalisations,
            'changements' => $changements,
            'data' => $statutLigne === 'mise_a_jour' ? array_intersect_key($donnees, array_flip($clesAEcrire)) : [],
            'produit_id' => $produit->id,
        ], false];
    }

    /**
     * Résout une référence facultative (categorie_reference/fournisseur_reference) selon la
     * convention à 3 états, écrit `$idChamp` dans $donnees et le diff lisible (référence
     * avant/après, "Aucun" si absent) dans $changements — factorisé car catégorie et
     * fournisseur partagent exactement la même forme de résolution.
     *
     * @param  Collection<int, object>  $candidats
     * @param  callable(object): string  $reference
     * @param  callable(object): string  $label
     */
    private function resoudreReference(
        Collection $ligne,
        string $champColonne,
        int $numeroLigne,
        bool $estCreation,
        Collection $candidats,
        callable $reference,
        callable $label,
        string $idChamp,
        string $labelDiff,
        ?string $idActuel,
        ?string $referenceActuelle,
        array &$donnees,
        array &$changements,
        array &$erreurs,
    ): void {
        $brut = $this->cellule($ligne, $champColonne);
        if ($this->estVider($brut)) {
            if ($estCreation) {
                $erreurs[] = "Ligne {$numeroLigne} — `{$champColonne}` : #VIDER# n'est pas autorisé à la création.";

                return;
            }
            $donnees[$idChamp] = null;
            if ($idActuel !== null) {
                $changements[$labelDiff] = ['avant' => $referenceActuelle ?? 'Aucun', 'apres' => 'Aucun'];
            }

            return;
        }
        if ($brut === '') {
            return;
        }

        $candidat = ReferenceValueResolver::matchExact($brut, $candidats, $reference);
        if (! $candidat) {
            $suggestion = ReferenceValueResolver::suggestClosest($brut, $candidats, $reference);
            $erreurs[] = $suggestion
                ? "Ligne {$numeroLigne} — `{$champColonne}` : \"{$brut}\" introuvable. Vouliez-vous dire \"{$suggestion}\" ?"
                : "Ligne {$numeroLigne} — `{$champColonne}` : \"{$brut}\" introuvable.";

            return;
        }

        $donnees[$idChamp] = $candidat->id;
        $nouvelleReference = $reference($candidat);
        if (! $estCreation && $nouvelleReference !== $referenceActuelle) {
            $changements[$labelDiff] = ['avant' => $referenceActuelle ?? 'Aucun', 'apres' => $nouvelleReference];
        }
    }

    private function resoudreStatut(string $brut): ?ProduitStatut
    {
        foreach (ProduitStatut::cases() as $statut) {
            if (mb_strtolower($brut, 'UTF-8') === $statut->value || mb_strtolower($brut, 'UTF-8') === mb_strtolower($statut->label(), 'UTF-8')) {
                return $statut;
            }
        }

        return null;
    }

    private function resoudreBool(string $brut): ?bool
    {
        $v = mb_strtolower(trim($brut), 'UTF-8');

        return match (true) {
            in_array($v, ['oui', 'yes', 'true', '1', 'vrai'], true) => true,
            in_array($v, ['non', 'no', 'false', '0', 'faux'], true) => false,
            default => null,
        };
    }

    /**
     * Reproduit EXACTEMENT ProduitVariante::setSkuAttribute() (espaces retirés + majuscules) —
     * jamais la tolérance zéros initiaux/tirets de ReferenceValueResolver::normalizeCodeKey(),
     * trop permissive pour un identifiant qui déclenche une écriture ciblée : "1" et "001", ou
     * "ABC-1" et "ABC1", sont des SKU distincts en base et doivent le rester à la comparaison,
     * sous peine de faire correspondre — et donc mettre à jour — le mauvais produit.
     */
    private static function normaliserSku(string $valeur): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', '', $valeur) ?? $valeur), 'UTF-8');
    }

    /**
     * Index un ensemble de variantes par SKU normalisé, en détectant explicitement toute
     * collision plutôt que de laisser Collection::keyBy() choisir silencieusement la dernière
     * occurrence. Ne devrait structurellement jamais se produire (unique(organization_id, sku)
     * en base, et normaliserSku() reproduit le mutateur donc ne fusionne plus deux valeurs
     * stockées distinctes) — mais une anomalie de données ne doit jamais aboutir à une mise à
     * jour silencieuse du mauvais produit : on interrompt explicitement plutôt que de deviner.
     *
     * @param  Collection<int, ProduitVariante>  $variantes
     * @return Collection<string, ProduitVariante>
     */
    private function indexerParSku(Collection $variantes): Collection
    {
        $index = [];
        foreach ($variantes as $variante) {
            $cle = self::normaliserSku($variante->sku);
            if (isset($index[$cle]) && $index[$cle]->id !== $variante->id) {
                throw new \RuntimeException(
                    "Anomalie de données : les variantes « {$index[$cle]->sku} » et « {$variante->sku} » de l'organisation {$variante->organization_id} partagent le même SKU normalisé « {$cle} »."
                );
            }
            $index[$cle] = $variante;
        }

        return collect($index);
    }

    private function cellule(Collection $ligne, string $champ): string
    {
        if ($champ === 'prix_usine') {
            $canonique = $this->celluleBrute($ligne, self::COLONNE_PRIX_USINE_AUTRES_VEHICULES);

            return $canonique !== '' ? $canonique : $this->celluleBrute($ligne, 'prix_usine');
        }

        return $this->celluleBrute($ligne, $champ);
    }

    private function celluleBrute(Collection $ligne, string $champ): string
    {
        return trim((string) ($ligne[$champ] ?? ''));
    }

    private function estVider(string $brut): bool
    {
        return mb_strtoupper($brut, 'UTF-8') === self::SENTINEL_VIDER;
    }

    private function differe(mixed $nouveau, mixed $ancien): bool
    {
        $n = $nouveau === null ? null : (string) $nouveau;
        $a = $ancien === null ? null : (string) $ancien;

        return $n !== $a;
    }

    private function erreur(?int $numeroLigne, ?string $sku, array $erreurs, array $avertissements = [], array $normalisations = []): array
    {
        return [
            'numero_ligne' => $numeroLigne,
            'sku' => $sku,
            'nom' => null,
            'statut' => 'erreur',
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'normalisations' => $normalisations,
            'changements' => [],
            'data' => null,
        ];
    }

    private function lireFeuille(Spreadsheet $spreadsheet): Collection
    {
        $sheet = $spreadsheet->getSheetByName('PRODUITS') ?? $spreadsheet->getSheet(0);
        $tableau = $sheet->toArray(null, true, true, false);
        if (count($tableau) < 1) {
            return collect();
        }

        $entetes = array_map(fn ($e) => trim((string) $e), array_shift($tableau));
        $nbColonnes = count($entetes);

        return collect($tableau)
            ->map(function ($ligne) use ($entetes, $nbColonnes) {
                $valeurs = array_slice(array_pad($ligne, $nbColonnes, null), 0, $nbColonnes);

                return collect(array_combine($entetes, $valeurs));
            })
            // Ignore les lignes entièrement vides (fin de feuille Excel).
            ->filter(fn ($ligne) => $ligne->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty())
            ->values();
    }
}
