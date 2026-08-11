<?php

namespace App\Services\ImportSites;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\ImportFlotte\Normalizers\ImportTextNormalizer;
use App\Services\ImportFlotte\Normalizers\PhoneNormalizer;
use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lit et valide un fichier d'import "sites" (une feuille, une ligne par site).
 * Réutilise les normaliseurs génériques de l'import flotte (téléphone, texte,
 * résolution de valeur de référence) plutôt que de dupliquer cette logique —
 * voir App\Services\ImportFlotte\Normalizers.
 *
 * Ne modifie jamais la base : composant de lecture seule, appelé à la fois
 * pour l'aperçu et juste avant l'exécution réelle (SiteImportExecutor),
 * pour éviter tout écart entre ce qui a été prévisualisé et ce qui est
 * enregistré — même principe que ImportFlotteParser.
 *
 * Le site parent (site_parent_facultatif) est résolu par NOM plutôt que par
 * ID technique : c'est ce que l'utilisateur métier connaît, et ça permet à un
 * même fichier de contenir à la fois un site et ses enfants (cf. § validation
 * ci-dessous, qui accepte un parent référencé ailleurs dans le fichier, pas
 * seulement déjà en base).
 */
class SiteImportParser
{
    public function analyserFichier(string $absolutePath, string $orgId): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $lignes = $this->lireFeuille($spreadsheet);

        return $this->analyser($lignes, $orgId);
    }

    public function analyser(Collection $lignes, string $orgId): array
    {
        if ($lignes->isEmpty()) {
            return ['nb_lignes_total' => 0, 'lignes' => []];
        }

        // Sites déjà en base pour cette organisation — un site importé dont le
        // nom correspond (une fois normalisé) à l'un d'eux est considéré comme
        // "déjà existant" et n'est jamais recréé ni modifié (cf. SiteImportExecutor).
        $sitesExistants = Site::where('organization_id', $orgId)->get(['id', 'nom']);
        $idParNomExistant = $sitesExistants
            ->mapWithKeys(fn (Site $s) => [ImportTextNormalizer::normalize($s->nom) => $s->id]);

        // Tous les noms présents dans le fichier (avant validation ligne à
        // ligne) : un site_parent_facultatif peut désigner un site qui
        // n'existe pas encore en base mais qui est une AUTRE ligne de ce
        // même fichier — voir docblock de la classe.
        $nomsDuFichier = $lignes
            ->map(fn ($l) => trim((string) ($l['nom'] ?? '')))
            ->filter(fn ($n) => $n !== '')
            ->unique(fn ($n) => ImportTextNormalizer::normalize($n))
            ->values();

        $candidatsParent = $nomsDuFichier
            ->map(fn ($n) => (object) ['nom' => $n])
            ->concat($sitesExistants->map(fn (Site $s) => (object) ['nom' => $s->nom]))
            ->unique(fn ($c) => ImportTextNormalizer::normalize($c->nom))
            ->values();

        $premiereLigneParNom = [];
        $resultats = [];

        foreach ($lignes as $index => $ligne) {
            $numeroLigne = $index + 2;
            $resultats[] = $this->analyserLigne(
                $ligne,
                $numeroLigne,
                $idParNomExistant,
                $candidatsParent,
                $premiereLigneParNom
            );
        }

        return ['nb_lignes_total' => $lignes->count(), 'lignes' => $resultats];
    }

    private function analyserLigne(
        Collection $ligne,
        int $numeroLigne,
        Collection $idParNomExistant,
        Collection $candidatsParent,
        array &$premiereLigneParNom
    ): array {
        $erreurs = [];
        $normalisations = [];

        $nom = trim((string) ($ligne['nom'] ?? ''));
        if ($nom === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `nom` : obligatoire.";

            return $this->erreur($numeroLigne, null, $erreurs);
        }

        $nomNormalise = ImportTextNormalizer::normalize($nom);
        if (isset($premiereLigneParNom[$nomNormalise])) {
            $erreurs[] = "Ligne {$numeroLigne} — `nom` : « {$nom} » apparaît déjà ligne {$premiereLigneParNom[$nomNormalise]} de ce fichier.";

            return $this->erreur($numeroLigne, $nom, $erreurs);
        }
        $premiereLigneParNom[$nomNormalise] = $numeroLigne;

        // ── Type ─────────────────────────────────────────────────────────────
        $typeSaisi = trim((string) ($ligne['type'] ?? ''));
        $type = null;
        if ($typeSaisi === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `type` : obligatoire.";
        } else {
            $type = ReferenceValueResolver::matchExact($typeSaisi, SiteType::cases(), fn (SiteType $t) => $t->label());
            if ($type && $type->label() !== $typeSaisi) {
                $normalisations[] = "\"{$typeSaisi}\" → \"{$type->label()}\"";
            } elseif (! $type) {
                $suggestion = ReferenceValueResolver::suggestClosest($typeSaisi, SiteType::cases(), fn (SiteType $t) => $t->label());
                $valeursAutorisees = implode(', ', array_map(fn (SiteType $t) => $t->label(), SiteType::cases()));
                $erreurs[] = $suggestion
                    ? "Ligne {$numeroLigne} — `type` : valeur \"{$typeSaisi}\" invalide. Vouliez-vous dire \"{$suggestion}\" ? (valeurs autorisées : {$valeursAutorisees})"
                    : "Ligne {$numeroLigne} — `type` : valeur \"{$typeSaisi}\" invalide (valeurs autorisées : {$valeursAutorisees}).";
            }
        }

        // ── Ville / quartier / téléphone (obligatoires pour l'import) ──────────
        $ville = trim((string) ($ligne['ville_obligatoire'] ?? ''));
        if ($ville === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `ville_obligatoire` : obligatoire.";
        }

        $quartier = trim((string) ($ligne['quartier_obligatoire'] ?? ''));
        if ($quartier === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `quartier_obligatoire` : obligatoire.";
        }

        $telephoneBrut = trim((string) ($ligne['telephone_obligatoire'] ?? ''));
        $telephone = null;
        if ($telephoneBrut === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `telephone_obligatoire` : obligatoire.";
        } else {
            $phone = (new PhoneNormalizer)->normalize($telephoneBrut, 'GN');
            if ($phone['erreur']) {
                $erreurs[] = "Ligne {$numeroLigne} — `telephone_obligatoire` : {$phone['erreur']}";
            } else {
                $telephone = $phone['telephone'];
                if ($telephone !== $telephoneBrut) {
                    $normalisations[] = "\"{$telephoneBrut}\" → \"{$telephone}\"";
                }
            }
        }

        // ── Description (facultative) ───────────────────────────────────────
        $description = trim((string) ($ligne['description_facultatif'] ?? ''));
        if ($description !== '' && mb_strlen($description) > 1000) {
            $erreurs[] = "Ligne {$numeroLigne} — `description_facultatif` : ne peut pas dépasser 1000 caractères.";
        }

        // ── Site parent (facultatif, résolu par nom) ────────────────────────
        $parentSaisi = trim((string) ($ligne['site_parent_facultatif'] ?? ''));
        $parentNom = null;
        if ($parentSaisi !== '') {
            if (ImportTextNormalizer::normalize($parentSaisi) === $nomNormalise) {
                $erreurs[] = "Ligne {$numeroLigne} — `site_parent_facultatif` : un site ne peut pas être son propre parent.";
            } else {
                $parent = ReferenceValueResolver::matchExact($parentSaisi, $candidatsParent, fn ($c) => $c->nom);
                if ($parent) {
                    $parentNom = $parent->nom;
                    if ($parentNom !== $parentSaisi) {
                        $normalisations[] = "\"{$parentSaisi}\" → \"{$parentNom}\" (site parent)";
                    }
                } else {
                    $erreurs[] = "Ligne {$numeroLigne} — `site_parent_facultatif` : site parent « {$parentSaisi} » introuvable.";
                }
            }
        }

        // ── Longitude / latitude (facultatives) ─────────────────────────────
        $longitude = $this->coordonneeOuNull($ligne['longitude_facultatif'] ?? null, -180, 180, 'longitude_facultatif', $numeroLigne, $erreurs);
        $latitude = $this->coordonneeOuNull($ligne['latitude_facultatif'] ?? null, -90, 90, 'latitude_facultatif', $numeroLigne, $erreurs);

        if (! empty($erreurs)) {
            return $this->erreur($numeroLigne, $nom, $erreurs, $normalisations);
        }

        $idExistant = $idParNomExistant->get($nomNormalise);

        return [
            'numero_ligne' => $numeroLigne,
            'nom' => $nom,
            'statut' => $idExistant ? 'existant' : 'nouveau',
            'erreurs' => [],
            'normalisations' => $normalisations,
            'data' => [
                'nom' => $nom,
                'type' => $type->value,
                'type_label' => $type->label(),
                'ville' => $ville,
                'quartier' => $quartier,
                'telephone' => $telephone,
                'description' => $description !== '' ? $description : null,
                'parent_nom' => $parentNom,
                'longitude' => $longitude,
                'latitude' => $latitude,
                'existing_id' => $idExistant,
            ],
        ];
    }

    private function coordonneeOuNull(mixed $valeur, float $min, float $max, string $champ, int $numeroLigne, array &$erreurs): ?float
    {
        $brut = trim((string) ($valeur ?? ''));
        if ($brut === '') {
            return null;
        }
        if (! is_numeric($brut) || (float) $brut < $min || (float) $brut > $max) {
            $erreurs[] = "Ligne {$numeroLigne} — `{$champ}` : \"{$brut}\" invalide (nombre entre {$min} et {$max} attendu).";

            return null;
        }

        return (float) $brut;
    }

    private function erreur(int $numeroLigne, ?string $nom, array $erreurs, array $normalisations = []): array
    {
        return [
            'numero_ligne' => $numeroLigne,
            'nom' => $nom,
            'statut' => 'erreur',
            'erreurs' => $erreurs,
            'normalisations' => $normalisations,
            'data' => null,
        ];
    }

    private function lireFeuille(Spreadsheet $spreadsheet): Collection
    {
        $sheet = $spreadsheet->getSheet(0);
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
