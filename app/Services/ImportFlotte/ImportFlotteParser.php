<?php

namespace App\Services\ImportFlotte;

use App\Enums\TypeImportFlotte;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use App\Services\ImportFlotte\Normalizers\CountryNormalizer;
use App\Services\ImportFlotte\Normalizers\ImportTextNormalizer;
use App\Services\ImportFlotte\Normalizers\PhoneNormalizer;
use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lit un fichier d'import "flotte" à deux feuilles ("vehicules" : une ligne par
 * véhicule + son propriétaire ; "livreurs" : une ligne par livreur à rattacher,
 * reliée au véhicule par vehicule_immatriculation — ce qui évite de répéter les
 * infos véhicule/propriétaire à chaque livreur).
 *
 * La feuille "livreurs" est facultative : un véhicule (nouveau ou existant)
 * peut être importé sans aucun livreur rattaché. Dans ce cas, aucune équipe
 * n'est créée pour lui — la création du véhicule et celle de son équipe sont
 * dissociées : un nouveau véhicule sans livreur reste sans équipe tant qu'un
 * import ultérieur (ou une action manuelle depuis le back-office) ne lui en
 * rattache pas ; un véhicule existant sans ligne "livreurs" ne touche jamais
 * à son équipe ni à ses membres déjà en place (absence de ligne = "ne rien
 * changer", jamais "vider l'équipe").
 *
 * La commission et la répartition par membre (montant_par_pack) ne sont PAS
 * saisies dans le fichier : l'équipe est créée avec commission=0 et montants=0
 * ("brouillon"), à finaliser ensuite dans Équipes de livraison (qui applique
 * son propre contrôle de partage à ce moment-là). Valide chaque groupe contre
 * les autres règles métier de EquipeLivraisonController (exclusivité des
 * livreurs, unicité véhicule/équipe, format téléphone...).
 *
 * Ne modifie jamais la base : c'est un composant de lecture seule, réutilisé
 * à la fois pour l'aperçu (analyse) et juste avant l'exécution réelle (pour
 * éviter tout écart entre ce qui a été affiché et ce qui est enregistré).
 *
 * Second mode, TypeImportFlotte::LIVREURS (voir analyserLivreursSeuls()) :
 * fichier à une seule feuille "livreurs", sans feuille "vehicules" — chaque
 * immatriculation est résolue directement contre les véhicules déjà en base
 * (jamais de création de véhicule/propriétaire dans ce mode). Sert le cas
 * d'usage le plus courant une fois la flotte initiale importée : ajouter ou
 * mettre à jour des livreurs sans avoir à retaper toute la ligne véhicule
 * (nom, type, site, capacité...) juste pour l'utiliser comme ancrage.
 *
 * Usages livraison_vente/livraison_logistique (colonnes vehicule_livraison_vente /
 * vehicule_livraison_logistique, cf. toUsageBool()) : une cellule vide/absente vaut
 * "non" — aucun usage saisi = aucun usage attribué, jamais un usage vente par défaut.
 * Un véhicule sans aucun des deux usages est importé quand même (pas une erreur) mais
 * reste alors non exploitable dans les opérations métier tant qu'un usage n'est pas défini
 * (cf. Vehicule::aAuMoinsUnUsage(), scopes livraisonVente()/livraisonLogistique()). Sur un
 * véhicule déjà existant (immatriculation déjà en base), une colonne vide reprend la
 * valeur déjà enregistrée plutôt que de la remettre à "non" — un import ne doit jamais
 * effacer silencieusement un usage déjà configuré. Ceci dit, l'exécuteur
 * (ImportFlotteExecutor) ne réécrit de toute façon aucun champ d'un véhicule déjà
 * existant : cette ligne ne sert alors que d'ancrage pour ses livreurs/son équipe, comme
 * documenté plus haut — le repli ci-dessus ne fait que garder l'aperçu cohérent avec ce
 * qui sera réellement appliqué.
 */
class ImportFlotteParser
{
    use PhoneHandlerTrait;

    /**
     * Garde-fou : le traitement est synchrone (dans le cycle de la requête HTTP,
     * pas de file d'attente), donc un fichier trop volumineux ferait attendre
     * l'utilisateur inutilement longtemps. Les deux feuilles sont lues
     * entièrement en collection (pas de lecture par chunks, incompatible avec
     * le rapprochement véhicule ↔ livreurs qui doit voir toutes les lignes des
     * deux feuilles). Volumétrie réelle du projet : quelques dizaines à
     * centaines de lignes par import — 500 laisse une bonne marge.
     */
    private const MAX_LIGNES = 500;

    public function analyser(string $absolutePath, string $organizationId, TypeImportFlotte $type = TypeImportFlotte::FLOTTE): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $lignesLivreurs = $this->lireFeuille($spreadsheet, 'livreurs');

        if ($type === TypeImportFlotte::LIVREURS) {
            return $this->analyserLivreursSeuls($lignesLivreurs, $organizationId);
        }

        $lignesVehicules = $this->lireFeuille($spreadsheet, 'vehicules');

        $nbLignesTotal = $lignesVehicules->count() + $lignesLivreurs->count();

        if ($erreur = $this->erreurVolumetrie($nbLignesTotal, 'Fichier vide, ou feuilles "vehicules"/"livreurs" introuvables.')) {
            return $erreur;
        }

        $immatsParTelephoneLivreur = $this->immatsParTelephoneLivreurEnDoublon($lignesLivreurs);

        // Regroupe les lignes de la feuille "livreurs" par immatriculation — les
        // lignes en conflit (même livreur sur plusieurs véhicules) en sont
        // exclues : elles sont remontées une seule fois, globalement, plutôt que
        // dupliquées dans chacun des groupes véhicule concernés — voir
        // groupesConflitLivreurMultiVehicules().
        $livreursParImmat = $this->livreursParImmatSansConflit($lignesLivreurs, $immatsParTelephoneLivreur);

        $groupes = [];
        $immatsTraitees = [];
        // Un même propriétaire (même téléphone canonique) peut apparaître sur
        // plusieurs lignes "vehicules" (plusieurs véhicules). Suivi à travers
        // tout le fichier pour ne le proposer en création qu'une seule fois —
        // voir resoudreProprietaire().
        $telephonesProprietairesVus = [];

        // Une même immatriculation ne doit apparaître qu'une seule fois dans
        // la feuille "vehicules" (contrairement au propriétaire, un véhicule
        // n'a normalement qu'une ligne). Sans ce contrôle, deux lignes
        // dupliquées passeraient toutes deux l'analyse ("valide") puis
        // provoqueraient une violation de contrainte d'unicité en base au
        // moment de la confirmation — plantage tardif et peu clair au lieu
        // d'une erreur d'analyse explicite.
        $occurrencesParImmat = $lignesVehicules
            ->map(fn ($ligne) => $this->normaliserImmatriculation((string) ($ligne['vehicule_immatriculation'] ?? '')))
            ->filter(fn ($immat) => $immat !== '')
            ->countBy();

        foreach ($lignesVehicules as $index => $ligneVehicule) {
            $immat = $this->normaliserImmatriculation((string) ($ligneVehicule['vehicule_immatriculation'] ?? ''));
            $immatsTraitees[$immat] = true;

            $lignesLivreursGroupe = ($livreursParImmat->get($immat) ?? collect())->all();

            if ($immat !== '' && ($occurrencesParImmat[$immat] ?? 0) > 1) {
                $groupes[] = [
                    'immatriculation' => $immat,
                    'ligne_vehicule' => $index + 2,
                    'lignes_livreurs' => array_column($lignesLivreursGroupe, 'numero_ligne'),
                    'statut' => 'erreur',
                    'erreurs' => ["Immatriculation \"{$immat}\" présente sur {$occurrencesParImmat[$immat]} lignes de la feuille \"vehicules\" — une seule ligne par véhicule est autorisée."],
                    'normalisations' => [],
                    'avertissements' => [],
                ];

                continue;
            }

            $groupes[] = $this->analyserGroupe($immat, $index + 2, $ligneVehicule, $lignesLivreursGroupe, $organizationId, $telephonesProprietairesVus);
        }

        // Lignes de la feuille "livreurs" dont l'immatriculation n'existe dans
        // aucune ligne de la feuille "vehicules".
        foreach ($livreursParImmat as $immat => $lignes) {
            if (isset($immatsTraitees[$immat])) {
                continue;
            }

            $groupes[] = [
                'immatriculation' => $immat !== '' ? $immat : null,
                'ligne_vehicule' => null,
                'lignes_livreurs' => array_column($lignes->all(), 'numero_ligne'),
                'statut' => 'erreur',
                'erreurs' => [$immat !== ''
                    ? "Aucun véhicule avec l'immatriculation \"{$immat}\" dans la feuille \"vehicules\"."
                    : 'Immatriculation manquante sur une ligne de la feuille "livreurs".'],
                'normalisations' => [],
                'avertissements' => [],
            ];
        }

        $groupes = array_merge($groupes, $this->groupesConflitLivreurMultiVehicules($lignesLivreurs, $immatsParTelephoneLivreur));

        return [
            'nb_lignes_total' => $nbLignesTotal,
            'groupes' => $groupes,
        ];
    }

    /**
     * Mode TypeImportFlotte::LIVREURS : aucune feuille "vehicules" — chaque
     * immatriculation de la feuille "livreurs" est résolue directement contre
     * les véhicules déjà en base (jamais de création de véhicule/propriétaire).
     */
    private function analyserLivreursSeuls(Collection $lignesLivreurs, string $orgId): array
    {
        $nbLignesTotal = $lignesLivreurs->count();

        if ($erreur = $this->erreurVolumetrie($nbLignesTotal, 'Fichier vide, ou feuille "livreurs" introuvable.')) {
            return $erreur;
        }

        $immatsParTelephoneLivreur = $this->immatsParTelephoneLivreurEnDoublon($lignesLivreurs);
        $livreursParImmat = $this->livreursParImmatSansConflit($lignesLivreurs, $immatsParTelephoneLivreur);

        $groupes = [];
        foreach ($livreursParImmat as $immat => $lignes) {
            $numerosLignesLivreurs = array_column($lignes->all(), 'numero_ligne');

            if ($immat === '') {
                $groupes[] = [
                    'immatriculation' => null,
                    'ligne_vehicule' => null,
                    'lignes_livreurs' => $numerosLignesLivreurs,
                    'statut' => 'erreur',
                    'erreurs' => ['Immatriculation manquante sur une ligne de la feuille "livreurs".'],
                    'normalisations' => [],
                    'avertissements' => [],
                ];

                continue;
            }

            $vehicule = Vehicule::where('organization_id', $orgId)
                ->where('immatriculation', $immat)
                ->whereNull('deleted_at')
                ->first();

            if (! $vehicule) {
                $groupes[] = [
                    'immatriculation' => $immat,
                    'ligne_vehicule' => null,
                    'lignes_livreurs' => $numerosLignesLivreurs,
                    'statut' => 'erreur',
                    'erreurs' => ["Aucun véhicule avec l'immatriculation \"{$immat}\" en base. Importez-le d'abord via l'import \"".TypeImportFlotte::FLOTTE->label().'".'],
                    'normalisations' => [],
                    'avertissements' => [],
                ];

                continue;
            }

            $equipeExistante = EquipeLivraison::where('vehicule_id', $vehicule->id)->whereNull('deleted_at')->first();

            [$livreurs, $erreurs, $normalisations] = $this->resoudreLivreurs($lignes->all(), $orgId, $equipeExistante);

            if (! empty($erreurs)) {
                $groupes[] = [
                    'immatriculation' => $immat,
                    'ligne_vehicule' => null,
                    'lignes_livreurs' => $numerosLignesLivreurs,
                    'statut' => 'erreur',
                    'erreurs' => $erreurs,
                    'normalisations' => $normalisations,
                    'avertissements' => [],
                ];

                continue;
            }

            $commission = $equipeExistante ? (float) $equipeExistante->commission_unitaire_par_pack : 0.0;

            $groupes[] = [
                'immatriculation' => $immat,
                'ligne_vehicule' => null,
                'lignes_livreurs' => $numerosLignesLivreurs,
                'statut' => 'valide',
                'erreurs' => [],
                'normalisations' => $normalisations,
                'avertissements' => [],
                'vehicule' => [
                    'existe' => true,
                    'id' => $vehicule->id,
                    'nom_vehicule' => $vehicule->nom_vehicule,
                    // Champs de création non applicables (véhicule déjà en base, jamais
                    // recréé/modifié dans ce mode) — jamais lus par ImportFlotteExecutor
                    // quand existe=true.
                    'type_vehicule_id' => null,
                    'capacite_packs' => null,
                    'capacite_bouteilles' => null,
                    'livraison_vente' => null,
                    'livraison_logistique' => null,
                    'site_id' => null,
                ],
                // Cf. docblock de classe : null = "aucune équipe pour ce groupe"
                // (pas de nouveaux livreurs et véhicule sans équipe existante).
                'equipe' => ($equipeExistante || count($livreurs) > 0) ? [
                    'existe' => (bool) $equipeExistante,
                    'id' => $equipeExistante?->id,
                    'commission_unitaire_par_pack' => $commission,
                    // Jamais de propriétaire tiers saisi dans ce mode (aucune feuille
                    // "vehicules") : le montant propriétaire d'une équipe existante
                    // n'est jamais touché ici.
                    'montant_par_pack_proprietaire' => null,
                ] : null,
                'proprietaire' => null,
                'livreurs' => $livreurs,
            ];
        }

        $groupes = array_merge($groupes, $this->groupesConflitLivreurMultiVehicules($lignesLivreurs, $immatsParTelephoneLivreur));

        return [
            'nb_lignes_total' => $nbLignesTotal,
            'groupes' => $groupes,
        ];
    }

    /**
     * @return array|null l'erreur "fichier vide"/"trop de lignes" prête à retourner
     *                    telle quelle par analyser(), ou null si le volume est valide.
     */
    private function erreurVolumetrie(int $nbLignesTotal, string $messageVide): ?array
    {
        if ($nbLignesTotal === 0) {
            return [
                'nb_lignes_total' => 0,
                'groupes' => [[
                    'immatriculation' => null,
                    'ligne_vehicule' => null,
                    'lignes_livreurs' => [],
                    'statut' => 'erreur',
                    'erreurs' => [$messageVide],
                    'normalisations' => [],
                    'avertissements' => [],
                ]],
            ];
        }

        if ($nbLignesTotal > self::MAX_LIGNES) {
            return [
                'nb_lignes_total' => $nbLignesTotal,
                'groupes' => [[
                    'immatriculation' => null,
                    'ligne_vehicule' => null,
                    'lignes_livreurs' => [],
                    'statut' => 'erreur',
                    'erreurs' => [sprintf(
                        'Le fichier contient trop de lignes (%d), maximum autorisé : %d. Scindez-le en plusieurs imports.',
                        $nbLignesTotal,
                        self::MAX_LIGNES
                    )],
                    'normalisations' => [],
                    'avertissements' => [],
                ]],
            ];
        }

        return null;
    }

    /**
     * Un même livreur (même téléphone canonique) ne peut pas être rattaché à
     * deux véhicules différents dans le même fichier — un livreur n'appartient
     * qu'à une seule équipe (règle déjà appliquée pour les livreurs déjà en
     * base, cf. $dejaAffecte dans resoudreLivreurs()). Sans ce contrôle, un
     * livreur pas encore en base et présent sur deux lignes "livreurs" de
     * véhicules différents passerait l'analyse puis violerait la contrainte
     * d'unicité (telephone, organization_id) de la table livreurs à la
     * confirmation.
     *
     * @return Collection<string, Collection<int, string>> immatriculations en
     *                                                     conflit, indexées par téléphone canonique.
     */
    private function immatsParTelephoneLivreurEnDoublon(Collection $lignesLivreurs): Collection
    {
        return $lignesLivreurs
            ->map(function ($ligne) {
                $phone = (new PhoneNormalizer)->normalize(trim((string) ($ligne['livreur_telephone'] ?? '')), 'GN');
                $immat = $this->normaliserImmatriculation((string) ($ligne['vehicule_immatriculation'] ?? ''));

                return $phone['telephone'] && $immat !== '' ? [$phone['telephone'], $immat] : null;
            })
            ->filter()
            ->groupBy(fn ($paire) => $paire[0])
            ->map(fn ($paires) => $paires->pluck(1)->unique()->values())
            ->filter(fn ($immats) => $immats->count() > 1);
    }

    /**
     * Regroupe les lignes "livreurs" par immatriculation, en excluant celles
     * dont le téléphone est en conflit (cf. immatsParTelephoneLivreurEnDoublon) :
     * ces lignes-là ne doivent être rattachées à AUCUN groupe véhicule tant que
     * le conflit n'est pas résolu — elles sont remontées séparément par
     * groupesConflitLivreurMultiVehicules().
     *
     * @return Collection<string, Collection<int, array{numero_ligne: int, donnees: Collection}>>
     */
    private function livreursParImmatSansConflit(Collection $lignesLivreurs, Collection $immatsParTelephoneLivreur): Collection
    {
        return $lignesLivreurs
            ->map(fn ($ligne, $index) => ['numero_ligne' => $index + 2, 'donnees' => $ligne])
            ->reject(function ($l) use ($immatsParTelephoneLivreur) {
                $phone = (new PhoneNormalizer)->normalize(trim((string) ($l['donnees']['livreur_telephone'] ?? '')), 'GN');

                return $phone['telephone'] && $immatsParTelephoneLivreur->has($phone['telephone']);
            })
            ->groupBy(fn ($l) => $this->normaliserImmatriculation((string) ($l['donnees']['vehicule_immatriculation'] ?? '')));
    }

    /**
     * Un groupe d'erreur par téléphone en conflit (plutôt qu'un par véhicule
     * concerné, cf. livreursParImmatSansConflit) : un même problème rattaché à
     * deux véhicules n'affichait sinon rien qu'un doublon quasi-identique du
     * même message dans deux groupes distincts — trompeur (donne l'impression
     * de deux problèmes indépendants alors qu'il n'y en a qu'un).
     *
     * @return array<int, array> groupes d'erreur prêts à ajouter au rapport
     */
    private function groupesConflitLivreurMultiVehicules(Collection $lignesLivreurs, Collection $immatsParTelephoneLivreur): array
    {
        if ($immatsParTelephoneLivreur->isEmpty()) {
            return [];
        }

        $lignesIndexees = $lignesLivreurs->map(fn ($ligne, $index) => ['numero_ligne' => $index + 2, 'donnees' => $ligne]);

        $groupes = [];
        foreach ($immatsParTelephoneLivreur as $telephone => $immats) {
            $lignesDuTelephone = $lignesIndexees->filter(function ($l) use ($telephone) {
                $phone = (new PhoneNormalizer)->normalize(trim((string) ($l['donnees']['livreur_telephone'] ?? '')), 'GN');

                return $phone['telephone'] === $telephone;
            });

            $detailParImmat = $immats->map(function ($immat) use ($lignesDuTelephone) {
                $numeros = $lignesDuTelephone
                    ->filter(fn ($l) => $this->normaliserImmatriculation((string) ($l['donnees']['vehicule_immatriculation'] ?? '')) === $immat)
                    ->pluck('numero_ligne');

                return sprintf('%s (ligne%s %s)', $immat, $numeros->count() > 1 ? 's' : '', $numeros->implode(', '));
            })->implode(', ');

            $groupes[] = [
                'immatriculation' => $immats->implode(', '),
                'ligne_vehicule' => null,
                'lignes_livreurs' => $lignesDuTelephone->pluck('numero_ligne')->sort()->values()->all(),
                'statut' => 'erreur',
                'erreurs' => ["Le livreur {$telephone} est rattaché à plusieurs véhicules différents dans ce fichier : {$detailParImmat} — un livreur ne peut appartenir qu'à une seule équipe."],
                'normalisations' => [],
                'avertissements' => [],
            ];
        }

        return $groupes;
    }

    private function lireFeuille(Spreadsheet $spreadsheet, string $nom): Collection
    {
        $sheet = $this->trouverFeuille($spreadsheet, $nom);
        if (! $sheet) {
            return collect();
        }

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

    /**
     * Comparaison tolérante à la casse ET aux accents (via ImportTextNormalizer,
     * comme pour tous les autres libellés de ce parseur) : un onglet renommé
     * "Véhicules" (accentué, comme partout ailleurs dans l'UI de l'appli) doit
     * être reconnu au même titre que "vehicules" — sans ça, la feuille semble
     * introuvable et toutes les lignes "livreurs" échouent avec un message
     * d'immatriculation "introuvable" trompeur (la feuille "vehicules" n'a
     * simplement jamais été lue).
     */
    private function trouverFeuille(Spreadsheet $spreadsheet, string $nom): ?Worksheet
    {
        $cible = ImportTextNormalizer::normalize($nom);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (ImportTextNormalizer::normalize($sheet->getTitle()) === $cible) {
                return $sheet;
            }
        }

        return null;
    }

    private function analyserGroupe(string $immatriculation, int $numeroLigneVehicule, Collection $ligneVehicule, array $lignesLivreursGroupe, string $orgId, array &$telephonesProprietairesVus): array
    {
        $numerosLignesLivreurs = array_column($lignesLivreursGroupe, 'numero_ligne');
        $erreurs = [];
        $normalisations = [];
        $avertissements = [];

        if ($immatriculation === '') {
            return [
                'immatriculation' => null,
                'ligne_vehicule' => $numeroLigneVehicule,
                'lignes_livreurs' => $numerosLignesLivreurs,
                'statut' => 'erreur',
                'erreurs' => ['Immatriculation manquante.'],
                'normalisations' => [],
                'avertissements' => [],
            ];
        }

        // ── Véhicule ─────────────────────────────────────────────────────────
        $nomVehicule = trim((string) ($ligneVehicule['vehicule_nom'] ?? ''));
        $typeNomSaisi = trim((string) ($ligneVehicule['vehicule_type'] ?? ''));
        $siteNomSaisi = trim((string) ($ligneVehicule['vehicule_site'] ?? ''));

        // Résolu ici (avant les colonnes d'usage ci-dessous, qui en ont besoin pour leur
        // repli) plutôt que plus bas dans la méthode comme avant — même requête, juste
        // remontée.
        $vehiculeExistant = Vehicule::where('organization_id', $orgId)
            ->where('immatriculation', $immatriculation)
            ->whereNull('deleted_at')
            ->first();

        // Colonnes facultatives — cellule vide/absente : "false" à la création d'un
        // nouveau véhicule (aucun usage saisi = aucun usage attribué, jamais un usage
        // vente par défaut comme avant), ou la valeur déjà en base à la mise à jour d'un
        // véhicule existant (une ligne sans cette colonne ne doit jamais effacer un usage
        // déjà configuré — cf. docblock de classe). Une valeur non vide mais non reconnue
        // (ni oui/non) est signalée en erreur plutôt qu'interprétée arbitrairement.
        [$livraisonVente, $erreurLivraisonVente] = $this->toUsageBool(
            $ligneVehicule['vehicule_livraison_vente'] ?? null,
            $vehiculeExistant?->livraison_vente ?? false
        );
        if ($erreurLivraisonVente) {
            $erreurs[] = "Usage vente invalide : {$erreurLivraisonVente}";
        }
        [$livraisonLogistique, $erreurLivraisonLogistique] = $this->toUsageBool(
            $ligneVehicule['vehicule_livraison_logistique'] ?? null,
            $vehiculeExistant?->livraison_logistique ?? false
        );
        if ($erreurLivraisonLogistique) {
            $erreurs[] = "Usage logistique invalide : {$erreurLivraisonLogistique}";
        }
        // Facultatives : laissées vides, le repli sur la capacité par défaut du
        // type de véhicule s'applique (cf. VehiculeController::vehiculeData()).
        [$capacitePacks, $erreurCapacitePacks] = $this->toCapaciteOrNull($ligneVehicule['vehicule_capacite_sachets'] ?? null);
        if ($erreurCapacitePacks) {
            $erreurs[] = "Capacité sachets invalide : {$erreurCapacitePacks}";
        }
        [$capaciteBouteilles, $erreurCapaciteBouteilles] = $this->toCapaciteOrNull($ligneVehicule['vehicule_capacite_bouteilles'] ?? null);
        if ($erreurCapaciteBouteilles) {
            $erreurs[] = "Capacité bouteilles invalide : {$erreurCapaciteBouteilles}";
        }

        if ($nomVehicule === '') {
            $erreurs[] = 'Nom du véhicule manquant.';
        }
        // Un véhicule sans aucun usage n'est plus une erreur de saisie : il est
        // simplement créé/laissé sans usage, donc non exploitable tant qu'un usage n'est
        // pas défini (cf. Vehicule::aAuMoinsUnUsage() et les scopes livraisonVente()/
        // livraisonLogistique(), qui l'excluent déjà de tous les sélecteurs opérationnels).

        $type = null;
        if ($typeNomSaisi === '') {
            $erreurs[] = 'Type de véhicule manquant.';
        } else {
            $typesDisponibles = TypeVehicule::where('organization_id', $orgId)->whereNull('deleted_at')->get();
            $type = ReferenceValueResolver::matchExact($typeNomSaisi, $typesDisponibles, fn ($t) => $t->nom);

            if ($type && $type->nom !== $typeNomSaisi) {
                $normalisations[] = "\"{$typeNomSaisi}\" → \"{$type->nom}\"";
            } elseif (! $type) {
                $suggestion = ReferenceValueResolver::suggestClosest($typeNomSaisi, $typesDisponibles, fn ($t) => $t->nom);
                if ($suggestion) {
                    $avertissements[] = "\"{$typeNomSaisi}\" semble correspondre à \"{$suggestion}\".";
                    $erreurs[] = "Type de véhicule introuvable : \"{$typeNomSaisi}\". Valeur proche trouvée : \"{$suggestion}\". Corrigez le fichier ou confirmez la correspondance.";
                } else {
                    $erreurs[] = "Type de véhicule introuvable : \"{$typeNomSaisi}\".";
                }
            }
        }

        // Chaque véhicule (interne ou externe) est rattaché à un site.
        $site = null;
        if ($siteNomSaisi === '') {
            $erreurs[] = 'Site obligatoire.';
        } else {
            $sitesDisponibles = Site::where('organization_id', $orgId)->whereNull('deleted_at')->get();
            $site = ReferenceValueResolver::matchExact(
                $siteNomSaisi,
                $sitesDisponibles,
                [fn ($s) => $s->nom, fn ($s) => $s->code],
                [fn ($s) => $s->code]
            );

            if ($site && $site->nom !== $siteNomSaisi && $site->code !== $siteNomSaisi) {
                $normalisations[] = "\"{$siteNomSaisi}\" → \"{$site->nom}\"";
            } elseif (! $site) {
                $suggestion = ReferenceValueResolver::suggestClosest($siteNomSaisi, $sitesDisponibles, fn ($s) => $s->nom);
                if ($suggestion) {
                    $avertissements[] = "\"{$siteNomSaisi}\" semble correspondre à \"{$suggestion}\".";
                    $erreurs[] = "Site introuvable : \"{$siteNomSaisi}\". Valeur proche trouvée : \"{$suggestion}\". Corrigez le fichier ou confirmez la correspondance.";
                } else {
                    $erreurs[] = "Site introuvable : \"{$siteNomSaisi}\".";
                }
            }
        }

        // $vehiculeExistant déjà résolu plus haut (nécessaire au repli des colonnes d'usage).
        $equipeExistante = $vehiculeExistant
            ? EquipeLivraison::where('vehicule_id', $vehiculeExistant->id)->whereNull('deleted_at')->first()
            : null;

        // ── Propriétaire (facultatif — propriété indépendante de l'usage vente/logistique) ──
        // Toute colonne proprietaire_* renseignée déclenche la résolution "tout ou rien" ;
        // aucune renseignée → propriétaire par défaut (organisation) appliqué à la persistance,
        // cf. ImportFlotteExecutor.
        $aUnProprietaireSaisi = trim((string) ($ligneVehicule['proprietaire_nom'] ?? '')) !== ''
            || trim((string) ($ligneVehicule['proprietaire_prenom'] ?? '')) !== ''
            || trim((string) ($ligneVehicule['proprietaire_telephone'] ?? '')) !== '';
        $proprietaireResolu = $aUnProprietaireSaisi
            ? $this->resoudreProprietaire($ligneVehicule, $orgId, $erreurs, $normalisations, $telephonesProprietairesVus)
            : null;

        // ── Équipe : commission et montant propriétaire non saisis dans le fichier.
        // Une équipe déjà existante conserve sa vraie commission (utile pour
        // calculer le taux des nouveaux membres rattachés) ; une équipe à créer
        // démarre à 0, à finaliser ensuite dans Équipes de livraison.
        $commission = $equipeExistante ? (float) $equipeExistante->commission_unitaire_par_pack : 0.0;
        $montantProprietaire = $aUnProprietaireSaisi
            ? ($equipeExistante ? (float) $equipeExistante->montant_par_pack_proprietaire : 0.0)
            : null;

        // ── Livreurs ─────────────────────────────────────────────────────────
        // La feuille "livreurs" est facultative : un nouveau véhicule peut être
        // créé sans aucun livreur, auquel cas aucune équipe n'est créée du tout
        // (voir construction de 'equipe' plus bas). Un véhicule existant sans
        // ligne livreur ne touche pas non plus à ses membres actuels.
        [$livreurs, $erreursLivreurs, $normalisationsLivreurs] = $this->resoudreLivreurs($lignesLivreursGroupe, $orgId, $equipeExistante);
        $erreurs = array_merge($erreurs, $erreursLivreurs);
        $normalisations = array_merge($normalisations, $normalisationsLivreurs);

        if (! empty($erreurs)) {
            return [
                'immatriculation' => $immatriculation,
                'ligne_vehicule' => $numeroLigneVehicule,
                'lignes_livreurs' => $numerosLignesLivreurs,
                'statut' => 'erreur',
                'erreurs' => $erreurs,
                'normalisations' => $normalisations,
                'avertissements' => $avertissements,
            ];
        }

        return [
            'immatriculation' => $immatriculation,
            'ligne_vehicule' => $numeroLigneVehicule,
            'lignes_livreurs' => $numerosLignesLivreurs,
            'statut' => 'valide',
            'erreurs' => [],
            'normalisations' => $normalisations,
            'avertissements' => $avertissements,
            'vehicule' => [
                'existe' => (bool) $vehiculeExistant,
                'id' => $vehiculeExistant?->id,
                'nom_vehicule' => $nomVehicule,
                'type_vehicule_id' => $type?->id,
                'capacite_packs' => $capacitePacks,
                'capacite_bouteilles' => $capaciteBouteilles,
                'livraison_vente' => $livraisonVente,
                'livraison_logistique' => $livraisonLogistique,
                'site_id' => $site?->id,
            ],
            // Pas d'équipe du tout (ni existante, ni à créer) pour un nouveau
            // véhicule sans aucun livreur dans le fichier : la création du
            // véhicule et celle de son équipe sont dissociées — voir docblock
            // de la classe. `null` ici, distinct de `existe: false`, signifie
            // "aucune équipe ne sera créée pour ce groupe".
            'equipe' => ($equipeExistante || count($livreurs) > 0) ? [
                'existe' => (bool) $equipeExistante,
                'id' => $equipeExistante?->id,
                'commission_unitaire_par_pack' => $commission,
                'montant_par_pack_proprietaire' => $montantProprietaire,
            ] : null,
            'proprietaire' => $proprietaireResolu,
            'livreurs' => $livreurs,
        ];
    }

    private function resoudreProprietaire(Collection $ligne, string $orgId, array &$erreurs, array &$normalisations, array &$telephonesProprietairesVus): ?array
    {
        $nom = trim((string) ($ligne['proprietaire_nom'] ?? ''));
        $prenom = trim((string) ($ligne['proprietaire_prenom'] ?? ''));
        $telephoneBrut = trim((string) ($ligne['proprietaire_telephone'] ?? ''));
        $paysBrut = trim((string) ($ligne['proprietaire_pays'] ?? ''));

        if ($nom === '' || $prenom === '' || $telephoneBrut === '') {
            $erreurs[] = 'Propriétaire incomplet : nom, prénom et téléphone sont obligatoires dès qu\'un des trois est renseigné.';

            return null;
        }

        $codePays = $paysBrut !== '' ? CountryNormalizer::resolve($paysBrut) : null;
        if (! $codePays) {
            $erreurs[] = "Pays introuvable : \"{$paysBrut}\". Utilisez un nom de pays ou un code ISO valide.";

            return null;
        }
        if ($codePays !== $paysBrut) {
            $normalisations[] = "\"{$paysBrut}\" → \"{$codePays}\"";
        }

        $phone = (new PhoneNormalizer)->normalize($telephoneBrut, $codePays);
        if ($phone['erreur']) {
            $erreurs[] = "Téléphone du propriétaire invalide : {$phone['erreur']}";

            return null;
        }
        if ($phone['telephone'] !== $telephoneBrut) {
            $normalisations[] = "\"{$telephoneBrut}\" → \"{$phone['telephone']}\"";
        }

        $data = $this->resolveCountryData(['code_pays' => $codePays]);
        $data['telephone'] = $phone['telephone'];
        $data = $this->normalizePersonData(array_merge($data, ['nom' => $nom, 'prenom' => $prenom]));

        $existant = Proprietaire::where('organization_id', $orgId)
            ->where('telephone', $data['telephone'])
            ->whereNull('deleted_at')
            ->first();

        // Un même propriétaire peut posséder plusieurs véhicules : plusieurs
        // lignes "vehicules" du fichier partagent alors le même téléphone.
        // Sans ce suivi, chaque ligne se croirait "nouvelle" (rien en base
        // avant confirmation) et créerait un doublon en base à l'exécution.
        $doublonFichier = ! $existant && isset($telephonesProprietairesVus[$data['telephone']]);
        if (! $existant) {
            $telephonesProprietairesVus[$data['telephone']] = true;
        }

        return [
            'existe' => (bool) $existant,
            'doublon_fichier' => $doublonFichier,
            'id' => $existant?->id,
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'telephone' => $data['telephone'],
            'code_pays' => $codePays,
            'pays' => $data['pays'] ?? null,
            'code_phone_pays' => $data['code_phone_pays'] ?? null,
        ];
    }

    /**
     * @param  array<int, array{numero_ligne: int, donnees: Collection}>  $lignesGroupe  jamais de
     *                                                                                   ligne dont le téléphone est en conflit multi-véhicules (déjà exclues en amont,
     *                                                                                   cf. livreursParImmatSansConflit) — remontées séparément par
     *                                                                                   groupesConflitLivreurMultiVehicules().
     * @return array{0: array, 1: string[], 2: string[]}
     */
    private function resoudreLivreurs(array $lignesGroupe, string $orgId, ?EquipeLivraison $equipeExistante): array
    {
        $livreurs = [];
        $erreurs = [];
        $normalisations = [];
        $telephonesVus = [];

        $membresExistants = $equipeExistante
            ? $equipeExistante->membres()->with('livreur')->get()->keyBy(fn ($m) => $m->livreur?->telephone)
            : collect();

        foreach ($lignesGroupe as $ligneInfo) {
            $ligne = $ligneInfo['donnees'];
            $numero = $ligneInfo['numero_ligne'];

            // Colonne principale (facultative — seul le téléphone est
            // obligatoire, cf. Livreur::$fillable). Compatibilité conservée
            // avec l'ancien format à deux colonnes (livreur_nom/livreur_prenom)
            // pour d'anciens fichiers, mais plus jamais affichée/documentée
            // dans le template Eau La Maman — voir ImportFlotteLivreursSheetExport.
            $nomComplet = trim((string) ($ligne['livreur_nom_complet'] ?? ''));
            if ($nomComplet === '') {
                $nomLegacy = trim((string) ($ligne['livreur_nom'] ?? ''));
                $prenomLegacy = trim((string) ($ligne['livreur_prenom'] ?? ''));
                $nomComplet = trim("{$prenomLegacy} {$nomLegacy}");
            }
            $telephoneBrut = trim((string) ($ligne['livreur_telephone'] ?? ''));
            $roleSaisi = trim((string) ($ligne['livreur_role'] ?? ''));
            $role = ImportTextNormalizer::normalize($roleSaisi);

            if ($telephoneBrut === '') {
                $erreurs[] = "Ligne {$numero} : livreur incomplet (téléphone obligatoire).";

                continue;
            }

            $phone = (new PhoneNormalizer)->normalize($telephoneBrut, 'GN');
            if ($phone['erreur']) {
                $erreurs[] = "Ligne {$numero} : téléphone livreur invalide (format guinéen +224XXXXXXXXX attendu).";

                continue;
            }
            $telephone = $phone['telephone'];
            if ($telephone !== $telephoneBrut) {
                $normalisations[] = "\"{$telephoneBrut}\" → \"{$telephone}\"";
            }

            if (! in_array($role, ['chauffeur', 'convoyeur'], true)) {
                $erreurs[] = "Ligne {$numero} : rôle invalide (attendu : chauffeur ou convoyeur).";

                continue;
            }
            if ($roleSaisi !== $role) {
                $normalisations[] = "\"{$roleSaisi}\" → \"{$role}\"";
            }

            if (isset($telephonesVus[$telephone])) {
                $erreurs[] = "Ligne {$numero} : le téléphone {$telephone} apparaît plusieurs fois pour ce véhicule.";

                continue;
            }
            $telephonesVus[$telephone] = true;

            // Déjà membre de CETTE équipe (si elle existe) → rattachement déjà en place, on l'ignore.
            if ($membresExistants->has($telephone)) {
                continue;
            }

            $livreurExistant = Livreur::where('organization_id', $orgId)
                ->where('telephone', $telephone)
                ->whereNull('deleted_at')
                ->first();

            if ($livreurExistant) {
                $dejaAffecte = EquipeLivreur::where('livreur_id', $livreurExistant->id)
                    ->whereHas('equipe', fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'))
                    ->when($equipeExistante, fn ($q) => $q->where('equipe_id', '!=', $equipeExistante->id))
                    ->exists();

                if ($dejaAffecte) {
                    $erreurs[] = "Ligne {$numero} : le livreur {$telephone} est déjà affecté à une autre équipe.";

                    continue;
                }
            }

            $livreurs[] = [
                'existe' => (bool) $livreurExistant,
                'id' => $livreurExistant?->id,
                // Facultatif, conservé tel quel (jamais découpé/reformaté :
                // peut être un surnom comme "Petit Moussa" ou "Chauffeur 1").
                'nom_complet' => $nomComplet !== '' ? $nomComplet : null,
                'telephone' => $telephone,
                'role' => $role,
                // Non saisi dans le fichier — répartition à finaliser dans Équipes de livraison.
                'montant_par_pack' => 0.0,
            ];
        }

        return [$livreurs, $erreurs, $normalisations];
    }

    /**
     * @return array{0: int|null, 1: string|null} valeur et message d'erreur —
     *                                            jamais les deux à la fois. Cellule vide = pas de capacité saisie (pas
     *                                            une erreur), distinct d'une valeur invalide.
     */
    private function toCapaciteOrNull(mixed $valeur): array
    {
        $brut = trim((string) ($valeur ?? ''));
        if ($brut === '') {
            return [null, null];
        }
        if (! is_numeric($brut) || (int) $brut != $brut || (int) $brut < 1 || (int) $brut > 99999) {
            return [null, "\"{$brut}\" (entier entre 1 et 99999 attendu)."];
        }

        return [(int) $brut, null];
    }

    private function normaliserImmatriculation(string $valeur): string
    {
        return mb_strtoupper(trim($valeur), 'UTF-8');
    }

    /**
     * @param  mixed  $valeur  cellule brute — vide/absente, une chaîne ("oui"/"1"/...), ou
     *                         un booléen PHP natif si PhpSpreadsheet a interprété la cellule
     *                         Excel comme telle (case à cocher / type booléen de la feuille).
     * @param  bool  $valeurParDefaut  appliquée si la cellule est vide/absente — jamais
     *                                  utilisée quand une valeur est présente mais non reconnue
     *                                  (cf. $erreur en retour dans ce cas).
     * @return array{0: bool, 1: string|null} valeur et message d'erreur — jamais les deux à
     *                                        la fois.
     */
    private function toUsageBool(mixed $valeur, bool $valeurParDefaut): array
    {
        if ($valeur === null || $valeur === '') {
            return [$valeurParDefaut, null];
        }

        $bool = $this->toBool($valeur);
        if ($bool === null) {
            return [$valeurParDefaut, sprintf(
                '"%s" non reconnu (attendu : oui/non, yes/no, 1/0, true/false).',
                trim((string) $valeur)
            )];
        }

        return [$bool, null];
    }

    private function toBool(mixed $valeur): ?bool
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        // PhpSpreadsheet peut retourner un booléen PHP natif pour une cellule Excel de
        // type booléen (formatData désactivé ou cellule sans format d'affichage) — sans ce
        // cas, (string) false ci-dessous donnerait "" et ferait passer un "non"/"faux"
        // légitime pour une valeur non reconnue.
        if (is_bool($valeur)) {
            return $valeur;
        }
        $v = ImportTextNormalizer::normalize((string) $valeur);

        return match ($v) {
            'oui', 'true', '1', 'vrai', 'yes', 'x' => true,
            'non', 'false', '0', 'faux', 'no' => false,
            default => null,
        };
    }
}
