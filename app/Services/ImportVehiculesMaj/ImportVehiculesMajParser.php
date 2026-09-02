<?php

namespace App\Services\ImportVehiculesMaj;

use App\Models\Categorie;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\ImportFlotte\Normalizers\CapaciteColonneResolver;
use App\Services\ImportFlotte\Normalizers\ImportTextNormalizer;
use App\Services\ImportFlotte\Normalizers\ImportValeurNormalizer;
use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lit un fichier de mise à jour en masse des véhicules — une seule feuille "vehicules", une
 * ligne par véhicule DÉJÀ EN BASE, identifié par `vehicule_immatriculation` (jamais de
 * création : cf. docblock de ImportVehiculesMajExecutor). Conçu pour être rempli à partir de
 * l'export "Exporter pour mise à jour" de VehiculeController::exportMaj() — mêmes colonnes,
 * dans le même ordre (cf. ExportVehiculesMajExport) — puis réimporté tel quel.
 *
 * Champs modifiables (liste fermée, jamais étendue implicitement) :
 * - vehicule_site
 * - capacite__<REFERENCE> (une colonne par catégorie du catalogue produit ayant une
 *   `reference`, même convention dynamique que ImportFlotteParser — cf. CapaciteColonneResolver)
 * - vehicule_livraison_vente / vehicule_livraison_logistique
 *
 * Toute autre colonne présente dans le fichier (nom, type, catégorie, propriétaire...) est
 * silencieusement ignorée : ce parseur ne lit jamais que les clés ci-dessus, quelle que soit la
 * forme du fichier déposé — aucune notion de "fill() global à partir de la ligne" n'existe même
 * au niveau analyse, encore moins à l'exécution (cf. ImportVehiculesMajExecutor).
 *
 * Cellule vide/absente = "ne pas modifier cette donnée" : ne devient jamais NULL/0/false, la
 * valeur déjà en base est simplement conservée (contrairement à une création, où une colonne
 * vide a un sens propre — ici, ce fichier ne crée jamais rien).
 *
 * Une immatriculation introuvable dans l'organisation courante (jamais dans une autre
 * organisation : la recherche est systématiquement scopée organization_id) est une erreur
 * bloquante pour SA ligne uniquement — jamais un fallback vers une création, contrairement à
 * ImportFlotteParser.
 */
class ImportVehiculesMajParser
{
    /**
     * Garde-fou de volumétrie — même principe et même plafond qu'ImportFlotteParser::MAX_LIGNES
     * (traitement synchrone, dans le cycle de la requête HTTP).
     */
    private const MAX_LIGNES = 500;

    private const CAPACITE_COLONNE_PREFIXE = 'capacite__';

    /**
     * @return array{nb_lignes_total: int, lignes: array<int, array>}
     */
    public function analyserFichier(string $absolutePath, string $orgId): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $this->trouverFeuille($spreadsheet);

        if (! $sheet) {
            return $this->rapportErreurGlobale('Fichier vide, ou aucune feuille exploitable.');
        }

        $tableau = $sheet->toArray(null, true, true, false);
        if (count($tableau) < 1) {
            return $this->rapportErreurGlobale('Fichier vide.');
        }

        $entetes = array_map(fn ($e) => trim((string) $e), array_shift($tableau));
        $nbColonnes = count($entetes);

        $lignes = collect($tableau)
            ->map(function ($ligne) use ($entetes, $nbColonnes) {
                $valeurs = array_slice(array_pad($ligne, $nbColonnes, null), 0, $nbColonnes);

                return collect(array_combine($entetes, $valeurs));
            })
            // Ignore les lignes entièrement vides (fin de feuille Excel).
            ->filter(fn ($ligne) => $ligne->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty())
            ->values();

        $nbLignesTotal = $lignes->count();

        if ($nbLignesTotal === 0) {
            return $this->rapportErreurGlobale('Fichier vide, ou feuille "vehicules" introuvable.');
        }

        if ($nbLignesTotal > self::MAX_LIGNES) {
            return $this->rapportErreurGlobale(sprintf(
                'Le fichier contient trop de lignes (%d), maximum autorisé : %d. Scindez-le en plusieurs imports.',
                $nbLignesTotal,
                self::MAX_LIGNES
            ));
        }

        // $entetes capturé AVANT array_combine (qui collapserait silencieusement un vrai
        // doublon d'en-tête) — nécessaire pour que CapaciteColonneResolver puisse détecter un
        // doublon de colonne "capacite__<REFERENCE>", même principe qu'ImportFlotteParser.
        $resolutionColonnesCapacite = CapaciteColonneResolver::resoudre($entetes, $orgId, self::CAPACITE_COLONNE_PREFIXE);
        if ($resolutionColonnesCapacite['erreur_doublon'] !== null) {
            return $this->rapportErreurGlobale($resolutionColonnesCapacite['erreur_doublon']);
        }
        $colonnesCapacite = $resolutionColonnesCapacite['colonnes'];

        // Une même immatriculation présente plusieurs fois dans le fichier : chaque
        // occurrence est bloquée en erreur plutôt que d'appliquer silencieusement la dernière
        // — un fichier de mise à jour doit rester une description non ambiguë.
        $occurrencesParImmat = $lignes
            ->map(fn ($ligne) => Vehicule::normaliserImmatriculation((string) ($ligne['vehicule_immatriculation'] ?? '')))
            ->filter(fn ($immat) => $immat !== '')
            ->countBy();

        $lignesRapport = [];
        foreach ($lignes as $index => $ligne) {
            $lignesRapport[] = $this->analyserLigne($index + 2, $ligne, $orgId, $colonnesCapacite, $occurrencesParImmat);
        }

        return ['nb_lignes_total' => $nbLignesTotal, 'lignes' => $lignesRapport];
    }

    /**
     * Cherche une feuille nommée "vehicules" (comparaison tolérante à la casse et aux accents,
     * cf. ImportTextNormalizer — un onglet renommé "Véhicules" doit être reconnu) ; à défaut,
     * retombe sur la première feuille du classeur plutôt que d'échouer : ce fichier n'a qu'une
     * seule feuille utile, contrairement au gabarit flotte à deux feuilles, donc un onglet
     * renommé/laissé par défaut par Excel ne doit pas bloquer l'import.
     */
    private function trouverFeuille(Spreadsheet $spreadsheet): ?Worksheet
    {
        $cible = ImportTextNormalizer::normalize('vehicules');

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (ImportTextNormalizer::normalize($sheet->getTitle()) === $cible) {
                return $sheet;
            }
        }

        return $spreadsheet->getSheetCount() > 0 ? $spreadsheet->getActiveSheet() : null;
    }

    /**
     * @param  array<int, array{cle: string, reference: string, categorie: ?Categorie}>  $colonnesCapacite
     * @param  Collection<string, int>  $occurrencesParImmat
     */
    private function analyserLigne(int $numeroLigne, Collection $ligne, string $orgId, array $colonnesCapacite, Collection $occurrencesParImmat): array
    {
        $immatBrut = trim((string) ($ligne['vehicule_immatriculation'] ?? ''));

        if ($immatBrut === '') {
            return $this->rapportLigneErreur($numeroLigne, null, ['Immatriculation manquante.']);
        }

        $immatNormalisee = Vehicule::normaliserImmatriculation($immatBrut);

        if (($occurrencesParImmat[$immatNormalisee] ?? 0) > 1) {
            return $this->rapportLigneErreur($numeroLigne, $immatBrut, [
                "Immatriculation \"{$immatBrut}\" présente plusieurs fois dans le fichier — une seule ligne par véhicule est autorisée.",
            ]);
        }

        // Scopé organization_id : une immatriculation existant seulement dans une autre
        // organisation est traitée exactement comme une immatriculation inexistante — jamais
        // trouvée, jamais modifiable (isolation multi-tenant).
        $vehicule = Vehicule::where('organization_id', $orgId)
            ->where('immatriculation_normalisee', $immatNormalisee)
            ->whereNull('deleted_at')
            ->with(['capacites', 'site'])
            ->first();

        if (! $vehicule) {
            $vehiculesOrg = Vehicule::where('organization_id', $orgId)->whereNull('deleted_at')->get();
            $suggestion = ReferenceValueResolver::suggestClosest(
                $immatNormalisee,
                $vehiculesOrg,
                fn (Vehicule $v) => (string) $v->immatriculation_normalisee,
            );

            $message = "Aucun véhicule avec l'immatriculation \"{$immatBrut}\" dans cette organisation.";
            $avertissements = [];
            if ($suggestion !== null) {
                $procheVehicule = $vehiculesOrg->first(fn (Vehicule $v) => $v->immatriculation_normalisee === $suggestion);
                $avertissements[] = "\"{$immatBrut}\" ressemble à un véhicule existant : \"{$procheVehicule->nom_vehicule}\" ({$procheVehicule->immatriculation}).";
                $message .= " Valeur proche trouvée : \"{$procheVehicule->immatriculation}\" ({$procheVehicule->nom_vehicule}).";
            }

            return $this->rapportLigneErreur($numeroLigne, $immatBrut, [$message], $avertissements);
        }

        $erreurs = [];
        $avertissements = [];
        $changements = [];
        $miseAJour = [];

        // ── Site ─────────────────────────────────────────────────────────────
        $siteBrut = trim((string) ($ligne['vehicule_site'] ?? ''));
        if ($siteBrut !== '') {
            $sitesDisponibles = Site::where('organization_id', $orgId)->whereNull('deleted_at')->get();
            $site = ReferenceValueResolver::matchExact(
                $siteBrut,
                $sitesDisponibles,
                [fn (Site $s) => $s->nom, fn (Site $s) => $s->code],
                [fn (Site $s) => $s->code]
            );

            if (! $site) {
                $suggestion = ReferenceValueResolver::suggestClosest($siteBrut, $sitesDisponibles, fn (Site $s) => $s->nom);
                $erreurs[] = $suggestion
                    ? "Site introuvable : \"{$siteBrut}\". Valeur proche trouvée : \"{$suggestion}\". Corrigez le fichier ou confirmez la correspondance."
                    : "Site introuvable : \"{$siteBrut}\".";
            } elseif ($site->id !== $vehicule->site_id) {
                $changements[] = [
                    'champ' => 'site',
                    'label' => 'Site',
                    'avant' => $vehicule->site?->nom ?? '—',
                    'apres' => $site->nom,
                ];
                $miseAJour['site_id'] = $site->id;
            }
        }

        // ── Capacités (une colonne "capacite__<REFERENCE>" par catégorie détectée) ─────────
        $capacitesExistantes = $vehicule->capacites->keyBy('categorie_id');
        $capacitesMaj = [];
        foreach ($colonnesCapacite as $colonne) {
            $brut = trim((string) ($ligne[$colonne['cle']] ?? ''));
            if ($brut === '') {
                // Cellule vide = capacité inchangée, jamais remise à zéro/null.
                continue;
            }

            $categorie = $colonne['categorie'];
            $categorieLabel = $categorie?->nom ?? $colonne['reference'];

            [$valeur, $erreurValeur] = ImportValeurNormalizer::toEntierOuNull($brut);
            if ($erreurValeur) {
                $erreurs[] = "Capacité \"{$categorieLabel}\" invalide : {$erreurValeur}";

                continue;
            }

            if ($categorie === null) {
                $erreurs[] = "Référence catégorie inconnue : {$colonne['reference']}";

                continue;
            }

            $existante = $capacitesExistantes->get($categorie->id);
            $valeurActuelle = $existante?->capacite_max;

            if ($valeurActuelle === $valeur) {
                continue;
            }

            $changements[] = [
                'champ' => "capacite:{$categorie->id}",
                'label' => "Capacité {$categorieLabel}",
                'avant' => $valeurActuelle ?? '—',
                'apres' => $valeur,
            ];
            $capacitesMaj[] = ['categorie_id' => $categorie->id, 'valeur' => $valeur];
        }
        if (! empty($capacitesMaj)) {
            $miseAJour['capacites'] = $capacitesMaj;
        }

        // ── Usages vente / logistique ────────────────────────────────────────
        foreach ([
            ['vehicule_livraison_vente', 'livraison_vente', 'Vente'],
            ['vehicule_livraison_logistique', 'livraison_logistique', 'Logistique'],
        ] as [$colonne, $champ, $label]) {
            $brut = $ligne[$colonne] ?? null;
            if ($brut === null || trim((string) $brut) === '') {
                continue;
            }

            $valeur = ImportValeurNormalizer::toBool($brut);
            if ($valeur === null) {
                $erreurs[] = "Usage {$label} invalide : \"{$brut}\" non reconnu (attendu : oui/non, yes/no, 1/0, true/false).";

                continue;
            }

            if ($valeur !== (bool) $vehicule->{$champ}) {
                $changements[] = [
                    'champ' => $champ,
                    'label' => $label,
                    'avant' => $vehicule->{$champ} ? 'Oui' : 'Non',
                    'apres' => $valeur ? 'Oui' : 'Non',
                ];
                $miseAJour[$champ] = $valeur;
            }
        }

        if (! empty($erreurs)) {
            return $this->rapportLigneErreur($numeroLigne, $immatBrut, $erreurs, $avertissements, $vehicule->id, $vehicule->nom_vehicule);
        }

        return [
            'ligne' => $numeroLigne,
            'immatriculation' => $immatBrut,
            'statut' => empty($changements) ? 'inchange' : 'mise_a_jour',
            'erreurs' => [],
            'avertissements' => $avertissements,
            'vehicule_id' => $vehicule->id,
            'vehicule_nom' => $vehicule->nom_vehicule,
            'changements' => $changements,
            'mise_a_jour' => $miseAJour,
        ];
    }

    private function rapportLigneErreur(int $numeroLigne, ?string $immatriculation, array $erreurs, array $avertissements = [], ?string $vehiculeId = null, ?string $vehiculeNom = null): array
    {
        return [
            'ligne' => $numeroLigne,
            'immatriculation' => $immatriculation,
            'statut' => 'erreur',
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'vehicule_id' => $vehiculeId,
            'vehicule_nom' => $vehiculeNom,
            'changements' => [],
            'mise_a_jour' => [],
        ];
    }

    /** @return array{nb_lignes_total: int, lignes: array<int, array>} */
    private function rapportErreurGlobale(string $message): array
    {
        return [
            'nb_lignes_total' => 0,
            'lignes' => [$this->rapportLigneErreur(1, null, [$message])],
        ];
    }
}
