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
 *
 * `code_facultatif` est l'identifiant métier de rapprochement : quand il est
 * renseigné et correspond (après normalisation) au code d'un site déjà
 * existant DE LA MÊME ORGANISATION, la ligne devient une mise à jour de ce
 * site plutôt qu'une création — le code prime alors sur le nom pour décider
 * du statut de la ligne. Quand il est absent, le comportement historique
 * (rapprochement par nom, jamais de modification d'un site existant) est
 * inchangé. Un code qui correspond à un site archivé (soft-deleted) de
 * l'organisation bloque la ligne en erreur : les codes ne sont jamais
 * réutilisés dans ce projet, y compris après suppression (cf. Site::boot()),
 * donc ni création (violerait l'unicité en base) ni mise à jour silencieuse
 * (ressusciterait un enregistrement archivé) ne sont acceptables ici.
 *
 * La comparaison des codes tolère l'absence de zéros initiaux ("1" doit
 * rapprocher "001", cf. ReferenceValueResolver::normalizeNumericCode() — déjà
 * utilisé ailleurs dans le projet pour ce même besoin de rapprochement de
 * code de site) : un utilisateur qui retape un code depuis un tableur ne
 * conserve pas toujours le zéro-padding d'origine. La valeur STOCKÉE n'est
 * en revanche jamais réécrite — cf. codeKey().
 */
class SiteImportParser
{
    /**
     * Clé de comparaison pour un code de site : équivalence numérique
     * tolérante aux zéros initiaux ("1" == "001") pour les codes purement
     * numériques, repli sur la normalisation texte standard sinon (codes
     * alphanumériques). Jamais utilisée pour la valeur stockée.
     */
    private function codeKey(string $code): string
    {
        return ReferenceValueResolver::normalizeNumericCode($code) ?? ImportTextNormalizer::normalize($code);
    }

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
        // "déjà existant" et n'est jamais recréé ni modifié (cf. SiteImportExecutor),
        // sauf s'il est rapproché par `code_facultatif` (mise à jour, voir plus bas).
        $sitesExistants = Site::where('organization_id', $orgId)->get(['id', 'nom', 'code']);
        $idParNomExistant = $sitesExistants
            ->mapWithKeys(fn (Site $s) => [ImportTextNormalizer::normalize($s->nom) => $s->id]);
        $idParCodeExistant = $sitesExistants
            ->mapWithKeys(fn (Site $s) => [$this->codeKey($s->code) => $s->id]);

        // Codes de sites archivés (soft-deleted) de l'organisation : jamais
        // réutilisables (cf. Site::boot()), donc un import qui cible l'un
        // d'eux doit être bloqué explicitement plutôt que de planter sur la
        // contrainte d'unicité en base (création) ou de ressusciter
        // silencieusement l'enregistrement (mise à jour).
        $codesArchives = Site::onlyTrashed()
            ->where('organization_id', $orgId)
            ->pluck('code')
            ->map(fn ($c) => $this->codeKey((string) $c));

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
        $premiereLigneParCode = [];
        $resultats = [];

        foreach ($lignes as $index => $ligne) {
            $numeroLigne = $index + 2;
            $resultats[] = $this->analyserLigne(
                $ligne,
                $numeroLigne,
                $idParNomExistant,
                $idParCodeExistant,
                $codesArchives,
                $sitesExistants,
                $candidatsParent,
                $premiereLigneParNom,
                $premiereLigneParCode
            );
        }

        return ['nb_lignes_total' => $lignes->count(), 'lignes' => $resultats];
    }

    private function analyserLigne(
        Collection $ligne,
        int $numeroLigne,
        Collection $idParNomExistant,
        Collection $idParCodeExistant,
        Collection $codesArchives,
        Collection $sitesExistants,
        Collection $candidatsParent,
        array &$premiereLigneParNom,
        array &$premiereLigneParCode
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
        $descriptionFournie = $description !== '';

        // ── Code (facultatif, identifiant métier de rapprochement) ──────────
        // Jamais de cast numérique : un code "001" doit rester la chaîne
        // "001" (Excel peut transformer une saisie numérique en nombre et
        // faire perdre les zéros initiaux — cf. docblock de la classe et le
        // format Texte forcé sur cette colonne dans SiteImportTemplateExport).
        $codeSaisi = trim((string) ($ligne['code_facultatif'] ?? ''));
        $code = null;
        $codeNormalise = null;
        if ($codeSaisi !== '') {
            if (mb_strlen($codeSaisi) > 50) {
                $erreurs[] = "Ligne {$numeroLigne} — `code_facultatif` : ne peut pas dépasser 50 caractères.";
            } else {
                $code = $codeSaisi;
                $codeNormalise = $this->codeKey($code);
                if (isset($premiereLigneParCode[$codeNormalise])) {
                    $erreurs[] = "Ligne {$numeroLigne} — `code_facultatif` : le code « {$code} » apparaît déjà ligne {$premiereLigneParCode[$codeNormalise]} de ce fichier.";
                } else {
                    $premiereLigneParCode[$codeNormalise] = $numeroLigne;
                    if ($codesArchives->contains($codeNormalise) && ! $idParCodeExistant->has($codeNormalise)) {
                        $erreurs[] = "Ligne {$numeroLigne} — `code_facultatif` : le code « {$code} » correspond à un site archivé (supprimé) de cette organisation ; impossible de le créer ou de le mettre à jour automatiquement via import.";
                    }
                }
            }
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
        $longitudeBrute = trim((string) ($ligne['longitude_facultatif'] ?? ''));
        $latitudeBrute = trim((string) ($ligne['latitude_facultatif'] ?? ''));
        $longitude = $this->coordonneeOuNull($ligne['longitude_facultatif'] ?? null, -180, 180, 'longitude_facultatif', $numeroLigne, $erreurs);
        $latitude = $this->coordonneeOuNull($ligne['latitude_facultatif'] ?? null, -90, 90, 'latitude_facultatif', $numeroLigne, $erreurs);

        if (! empty($erreurs)) {
            return $this->erreur($numeroLigne, $nom, $erreurs, $normalisations);
        }

        // Le code, quand il est renseigné, est la SEULE clé de rapprochement pour
        // cette ligne (prime sur le nom) — cf. docblock de la classe. Sans code,
        // comportement historique inchangé : rapprochement par nom.
        if ($code !== null) {
            $idExistant = $idParCodeExistant->get($codeNormalise);
            $statut = $idExistant ? 'mise_a_jour' : 'nouveau';
        } else {
            $idExistant = $idParNomExistant->get($nomNormalise);
            $statut = $idExistant ? 'existant' : 'nouveau';
        }

        // Un nom "nouveau" mais proche d'un site déjà en base (ex: "Cba (Lansanaya,
        // Kountia)" vs "CBA") n'est jamais bloqué — juste signalé, pour éviter de
        // créer silencieusement un doublon plutôt que de réutiliser le site existant.
        $avertissements = [];
        if ($statut === 'nouveau') {
            $suggestion = $this->trouverSiteProche($nom, $sitesExistants);
            if ($suggestion) {
                $avertissements[] = "Un site proche existe déjà : « {$suggestion} ». Vérifiez qu'il ne s'agit pas d'un doublon avant de confirmer.";
            }

            // Le code n'a pas résolu de site existant, mais un AUTRE site de
            // l'organisation porte exactement ce nom (avec un code différent) :
            // ce cas n'existe que depuis que le code prime sur le nom — sans lui,
            // ce nom exact aurait été rapproché en "existant" plutôt que créé.
            if ($code !== null && $idParNomExistant->has($nomNormalise)) {
                $avertissements[] = "Un site du même nom « {$nom} » existe déjà dans cette organisation, avec un code différent. Vérifiez qu'il ne s'agit pas d'un doublon avant de confirmer.";
            }
        }

        return [
            'numero_ligne' => $numeroLigne,
            'nom' => $nom,
            'statut' => $statut,
            'erreurs' => [],
            'normalisations' => $normalisations,
            'avertissements' => $avertissements,
            'data' => [
                'nom' => $nom,
                'code' => $code,
                'type' => $type->value,
                'type_label' => $type->label(),
                'ville' => $ville,
                'quartier' => $quartier,
                'telephone' => $telephone,
                'description' => $description !== '' ? $description : null,
                'description_fournie' => $descriptionFournie,
                'parent_nom' => $parentNom,
                'parent_fourni' => $parentSaisi !== '',
                'longitude' => $longitude,
                'longitude_fournie' => $longitudeBrute !== '',
                'latitude' => $latitude,
                'latitude_fournie' => $latitudeBrute !== '',
                'existing_id' => $idExistant,
            ],
        ];
    }

    /**
     * ReferenceValueResolver::suggestClosest() (Levenshtein, distance max 2) ne voit pas ce cas
     * réel : un nom importé qui reprend le nom d'un site existant en préfixe, complété d'une
     * précision entre parenthèses/virgule (ex: "Cba (Lansanaya, Kountia)" pour un site déjà
     * nommé "CBA") — l'écart de caractères dépasse largement une distance de 2. On vérifie donc
     * en plus une relation de préfixe entre noms normalisés, avant de retomber sur Levenshtein
     * pour les fautes de frappe classiques.
     */
    private function trouverSiteProche(string $nom, Collection $sitesExistants): ?string
    {
        $cible = ImportTextNormalizer::normalize($nom);

        foreach ($sitesExistants as $site) {
            $candidat = ImportTextNormalizer::normalize($site->nom);
            if ($candidat === '' || $candidat === $cible) {
                continue;
            }
            if (str_starts_with($cible, $candidat) || str_starts_with($candidat, $cible)) {
                return $site->nom;
            }
        }

        return ReferenceValueResolver::suggestClosest($nom, $sitesExistants, fn (Site $s) => $s->nom);
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
            'avertissements' => [],
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
