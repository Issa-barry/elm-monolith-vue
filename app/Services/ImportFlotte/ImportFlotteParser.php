<?php

namespace App\Services\ImportFlotte;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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
 * peut être importé sans aucun livreur rattaché. Une équipe brouillon est
 * quand même créée pour un nouveau véhicule (potentiellement sans membre), à
 * compléter plus tard depuis le back-office ou un import ultérieur ; un
 * véhicule existant sans ligne "livreurs" ne touche jamais à ses membres déjà
 * en place.
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

    public function analyser(string $absolutePath, string $organizationId): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $lignesVehicules = $this->lireFeuille($spreadsheet, 'vehicules');
        $lignesLivreurs = $this->lireFeuille($spreadsheet, 'livreurs');

        $nbLignesTotal = $lignesVehicules->count() + $lignesLivreurs->count();

        if ($nbLignesTotal === 0) {
            return [
                'nb_lignes_total' => 0,
                'groupes' => [[
                    'immatriculation' => null,
                    'ligne_vehicule' => null,
                    'lignes_livreurs' => [],
                    'statut' => 'erreur',
                    'erreurs' => ['Fichier vide, ou feuilles "vehicules"/"livreurs" introuvables.'],
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
                ]],
            ];
        }

        // Regroupe les lignes de la feuille "livreurs" par immatriculation.
        $livreursParImmat = $lignesLivreurs
            ->map(fn ($ligne, $index) => ['numero_ligne' => $index + 2, 'donnees' => $ligne])
            ->groupBy(fn ($l) => $this->normaliserImmatriculation((string) ($l['donnees']['vehicule_immatriculation'] ?? '')));

        $groupes = [];
        $immatsTraitees = [];

        foreach ($lignesVehicules as $index => $ligneVehicule) {
            $immat = $this->normaliserImmatriculation((string) ($ligneVehicule['vehicule_immatriculation'] ?? ''));
            $immatsTraitees[$immat] = true;

            $lignesLivreursGroupe = ($livreursParImmat->get($immat) ?? collect())->all();

            $groupes[] = $this->analyserGroupe($immat, $index + 2, $ligneVehicule, $lignesLivreursGroupe, $organizationId);
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
            ];
        }

        return [
            'nb_lignes_total' => $nbLignesTotal,
            'groupes' => $groupes,
        ];
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

    private function trouverFeuille(Spreadsheet $spreadsheet, string $nom): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (mb_strtolower(trim($sheet->getTitle()), 'UTF-8') === $nom) {
                return $sheet;
            }
        }

        return null;
    }

    private function analyserGroupe(string $immatriculation, int $numeroLigneVehicule, Collection $ligneVehicule, array $lignesLivreursGroupe, string $orgId): array
    {
        $numerosLignesLivreurs = array_column($lignesLivreursGroupe, 'numero_ligne');
        $erreurs = [];

        if ($immatriculation === '') {
            return [
                'immatriculation' => null,
                'ligne_vehicule' => $numeroLigneVehicule,
                'lignes_livreurs' => $numerosLignesLivreurs,
                'statut' => 'erreur',
                'erreurs' => ['Immatriculation manquante.'],
            ];
        }

        // ── Véhicule ─────────────────────────────────────────────────────────
        $nomVehicule = trim((string) ($ligneVehicule['vehicule_nom'] ?? ''));
        $typeNomSaisi = trim((string) ($ligneVehicule['vehicule_type'] ?? ''));
        $categorie = strtolower(trim((string) ($ligneVehicule['vehicule_categorie'] ?? '')));
        $siteNomSaisi = trim((string) ($ligneVehicule['vehicule_site'] ?? ''));
        $prisEnChargeParUsine = $this->toBool($ligneVehicule['vehicule_pris_en_charge_par_usine'] ?? null) ?? false;

        if ($nomVehicule === '') {
            $erreurs[] = 'Nom du véhicule manquant.';
        }
        if (! in_array($categorie, ['interne', 'externe'], true)) {
            $erreurs[] = 'Catégorie véhicule invalide (attendu : interne ou externe).';
        }

        $type = $typeNomSaisi !== ''
            ? TypeVehicule::where('organization_id', $orgId)->whereNull('deleted_at')
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($typeNomSaisi)])->first()
            : null;
        if ($typeNomSaisi === '') {
            $erreurs[] = 'Type de véhicule manquant.';
        } elseif (! $type) {
            $erreurs[] = "Type de véhicule introuvable : \"{$typeNomSaisi}\".";
        }

        $site = null;
        if ($categorie === 'interne') {
            if ($siteNomSaisi === '') {
                $erreurs[] = 'Site obligatoire pour un véhicule interne.';
            } else {
                $site = Site::where('organization_id', $orgId)->whereNull('deleted_at')
                    ->where(fn ($q) => $q
                        ->whereRaw('LOWER(nom) = ?', [mb_strtolower($siteNomSaisi)])
                        ->orWhereRaw('LOWER(code) = ?', [mb_strtolower($siteNomSaisi)]))
                    ->first();
                if (! $site) {
                    $erreurs[] = "Site introuvable : \"{$siteNomSaisi}\".";
                }
            }
        }

        $vehiculeExistant = Vehicule::where('organization_id', $orgId)
            ->where('immatriculation', $immatriculation)
            ->whereNull('deleted_at')
            ->first();

        $equipeExistante = $vehiculeExistant
            ? EquipeLivraison::where('vehicule_id', $vehiculeExistant->id)->whereNull('deleted_at')->first()
            : null;

        // ── Propriétaire (uniquement si externe) ────────────────────────────
        $proprietaireResolu = null;
        if ($categorie === 'externe') {
            $proprietaireResolu = $this->resoudreProprietaire($ligneVehicule, $orgId, $erreurs);
        }

        // ── Équipe : commission et montant propriétaire non saisis dans le fichier.
        // Une équipe déjà existante conserve sa vraie commission (utile pour
        // calculer le taux des nouveaux membres rattachés) ; une équipe à créer
        // démarre à 0, à finaliser ensuite dans Équipes de livraison.
        $commission = $equipeExistante ? (float) $equipeExistante->commission_unitaire_par_pack : 0.0;
        $montantProprietaire = $categorie === 'externe'
            ? ($equipeExistante ? (float) $equipeExistante->montant_par_pack_proprietaire : 0.0)
            : null;

        // ── Livreurs ─────────────────────────────────────────────────────────
        // La feuille "livreurs" est facultative : un nouveau véhicule peut être
        // créé sans aucun membre (équipe brouillon vide, complétée plus tard
        // depuis le back-office ou un import ultérieur). Un véhicule existant
        // sans ligne livreur ne touche pas non plus à ses membres actuels.
        [$livreurs, $erreursLivreurs] = $this->resoudreLivreurs($lignesLivreursGroupe, $orgId, $equipeExistante);
        $erreurs = array_merge($erreurs, $erreursLivreurs);

        if (! empty($erreurs)) {
            return [
                'immatriculation' => $immatriculation,
                'ligne_vehicule' => $numeroLigneVehicule,
                'lignes_livreurs' => $numerosLignesLivreurs,
                'statut' => 'erreur',
                'erreurs' => $erreurs,
            ];
        }

        return [
            'immatriculation' => $immatriculation,
            'ligne_vehicule' => $numeroLigneVehicule,
            'lignes_livreurs' => $numerosLignesLivreurs,
            'statut' => 'valide',
            'erreurs' => [],
            'vehicule' => [
                'existe' => (bool) $vehiculeExistant,
                'id' => $vehiculeExistant?->id,
                'nom_vehicule' => $nomVehicule,
                'type_vehicule_id' => $type?->id,
                'categorie' => $categorie,
                'site_id' => $site?->id,
                'pris_en_charge_par_usine' => $prisEnChargeParUsine,
            ],
            'equipe' => [
                'existe' => (bool) $equipeExistante,
                'id' => $equipeExistante?->id,
                'commission_unitaire_par_pack' => $commission,
                'montant_par_pack_proprietaire' => $montantProprietaire,
            ],
            'proprietaire' => $proprietaireResolu,
            'livreurs' => $livreurs,
        ];
    }

    private function resoudreProprietaire(Collection $ligne, string $orgId, array &$erreurs): ?array
    {
        $nom = trim((string) ($ligne['proprietaire_nom'] ?? ''));
        $prenom = trim((string) ($ligne['proprietaire_prenom'] ?? ''));
        $telephoneBrut = trim((string) ($ligne['proprietaire_telephone'] ?? ''));
        $codePays = strtoupper(trim((string) ($ligne['proprietaire_pays'] ?? '')));

        if ($nom === '' || $prenom === '' || $telephoneBrut === '') {
            $erreurs[] = 'Propriétaire incomplet (nom, prénom et téléphone obligatoires pour un véhicule externe).';

            return null;
        }

        if ($codePays === '' || ! isset(static::supportedPays()[$codePays])) {
            $erreurs[] = "Code pays du propriétaire invalide ou manquant : \"{$codePays}\".";

            return null;
        }

        $data = $this->resolveCountryData(['code_pays' => $codePays]);
        $data['telephone'] = $telephoneBrut;

        try {
            $this->validateLocalPhoneLength($data);
        } catch (ValidationException $e) {
            $erreurs[] = "Téléphone du propriétaire invalide : {$e->getMessage()}";

            return null;
        }

        $data = $this->normalizePersonData(array_merge($data, ['nom' => $nom, 'prenom' => $prenom]));

        $existant = Proprietaire::where('organization_id', $orgId)
            ->where('telephone', $data['telephone'])
            ->whereNull('deleted_at')
            ->first();

        return [
            'existe' => (bool) $existant,
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
     * @return array{0: array, 1: string[]}
     */
    private function resoudreLivreurs(array $lignesGroupe, string $orgId, ?EquipeLivraison $equipeExistante): array
    {
        $livreurs = [];
        $erreurs = [];
        $telephonesVus = [];

        $membresExistants = $equipeExistante
            ? $equipeExistante->membres()->with('livreur')->get()->keyBy(fn ($m) => $m->livreur?->telephone)
            : collect();

        foreach ($lignesGroupe as $ligneInfo) {
            $ligne = $ligneInfo['donnees'];
            $numero = $ligneInfo['numero_ligne'];

            $nom = trim((string) ($ligne['livreur_nom'] ?? ''));
            $prenom = trim((string) ($ligne['livreur_prenom'] ?? ''));
            $telephoneBrut = trim((string) ($ligne['livreur_telephone'] ?? ''));
            $role = strtolower(trim((string) ($ligne['livreur_role'] ?? '')));

            if ($nom === '' || $prenom === '' || $telephoneBrut === '') {
                $erreurs[] = "Ligne {$numero} : livreur incomplet (nom, prénom, téléphone obligatoires).";

                continue;
            }

            $telephone = $this->normaliserTelephoneLivreur($telephoneBrut);
            if (! preg_match('/^\+224\d{9}$/', $telephone)) {
                $erreurs[] = "Ligne {$numero} : téléphone livreur invalide (format guinéen +224XXXXXXXXX attendu).";

                continue;
            }

            if (! in_array($role, ['chauffeur', 'convoyeur'], true)) {
                $erreurs[] = "Ligne {$numero} : rôle invalide (attendu : chauffeur ou convoyeur).";

                continue;
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
                'nom' => mb_strtoupper($nom, 'UTF-8'),
                'prenom' => mb_convert_case(mb_strtolower($prenom, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
                'telephone' => $telephone,
                'role' => $role,
                // Non saisi dans le fichier — répartition à finaliser dans Équipes de livraison.
                'montant_par_pack' => 0.0,
            ];
        }

        return [$livreurs, $erreurs];
    }

    private function normaliserImmatriculation(string $valeur): string
    {
        return mb_strtoupper(trim($valeur), 'UTF-8');
    }

    private function normaliserTelephoneLivreur(string $brut): string
    {
        $digits = preg_replace('/\D+/', '', $brut) ?? '';

        if (str_starts_with($digits, '224') && strlen($digits) > 9) {
            $digits = substr($digits, 3);
        }
        $digits = preg_replace('/^0/', '', $digits) ?? $digits;

        return '+224'.$digits;
    }

    private function toBool(mixed $valeur): ?bool
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        $v = mb_strtolower(trim((string) $valeur), 'UTF-8');

        return match ($v) {
            'oui', 'true', '1', 'vrai', 'yes' => true,
            'non', 'false', '0', 'faux', 'no' => false,
            default => null,
        };
    }
}
