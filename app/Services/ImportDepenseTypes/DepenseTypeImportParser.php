<?php

namespace App\Services\ImportDepenseTypes;

use App\Enums\CategorieDepense;
use App\Models\DepenseType;
use App\Services\ImportFlotte\Normalizers\ReferenceValueResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lit et valide un fichier d'import "types de dépense" (une feuille, une
 * ligne par type). Réutilise le résolveur de valeur de référence générique de
 * l'import flotte (App\Services\ImportFlotte\Normalizers) plutôt que de
 * dupliquer cette logique — mêmes principes que SiteImportParser :
 *
 * - composant de lecture seule, ré-appelé à l'identique pour l'aperçu et
 *   juste avant l'exécution réelle (DepenseTypeImportExecutor), pour éviter
 *   tout écart entre ce qui a été prévisualisé et ce qui est enregistré ;
 * - AUCUNE mise à jour silencieuse d'un type existant : contrairement à
 *   l'import de sites, un libellé qui correspond (une fois transformé en
 *   `code`, cf. DepenseTypeController::generateCode) à un type déjà présent
 *   dans l'organisation — actif ou archivé, le code n'est jamais réutilisé,
 *   cf. contrainte unique (organization_id, code) — est bloqué en erreur, pas
 *   rapproché. C'est une décision produit explicite (cf. brief : « aucun
 *   écrasement silencieux d'un type existant »), donc aucun statut
 *   "existant"/"mise_a_jour" ici : seulement "nouveau" ou "erreur".
 */
class DepenseTypeImportParser
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

        // Codes déjà pris dans l'organisation, y compris les types archivés
        // (soft-deleted) — le code n'est jamais réutilisé (même logique que
        // DepenseTypeController::generateCode), donc un import qui viserait
        // un code archivé doit être bloqué explicitement.
        $codesExistants = DepenseType::withTrashed()
            ->where('organization_id', $orgId)
            ->pluck('code')
            ->map(fn ($c) => mb_strtolower((string) $c, 'UTF-8'))
            ->all();

        $premiereLigneParCode = [];
        $resultats = [];

        foreach ($lignes as $index => $ligne) {
            $numeroLigne = $index + 2;
            $resultats[] = $this->analyserLigne($ligne, $numeroLigne, $codesExistants, $premiereLigneParCode);
        }

        return ['nb_lignes_total' => $lignes->count(), 'lignes' => $resultats];
    }

    private function analyserLigne(Collection $ligne, int $numeroLigne, array $codesExistants, array &$premiereLigneParCode): array
    {
        $erreurs = [];
        $normalisations = [];

        // ── Libellé ──────────────────────────────────────────────────────────
        $libelle = trim((string) ($ligne['libelle'] ?? ''));
        if ($libelle === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `libelle` : obligatoire.";

            return $this->erreur($numeroLigne, null, $erreurs);
        }
        if (mb_strlen($libelle) > 100) {
            $erreurs[] = "Ligne {$numeroLigne} — `libelle` : ne peut pas dépasser 100 caractères.";
        }

        $code = mb_strtolower(Str::slug($libelle, '_'), 'UTF-8');
        if (isset($premiereLigneParCode[$code])) {
            $erreurs[] = "Ligne {$numeroLigne} — `libelle` : « {$libelle} » est un doublon de la ligne {$premiereLigneParCode[$code]} de ce fichier.";
        } else {
            $premiereLigneParCode[$code] = $numeroLigne;
            if (in_array($code, $codesExistants, true)) {
                $erreurs[] = "Ligne {$numeroLigne} — `libelle` : un type « {$libelle} » existe déjà (ou a été supprimé) dans cette organisation — aucun écrasement automatique, corrigez le libellé ou gérez le type existant directement.";
            }
        }

        // ── Concerné ─────────────────────────────────────────────────────────
        $concerneSaisi = trim((string) ($ligne['concerne'] ?? ''));
        $categorie = null;
        if ($concerneSaisi === '') {
            $erreurs[] = "Ligne {$numeroLigne} — `concerne` : obligatoire.";
        } else {
            $categorie = ReferenceValueResolver::matchExact($concerneSaisi, CategorieDepense::cases(), fn (CategorieDepense $c) => $c->label());
            if ($categorie && $categorie->label() !== $concerneSaisi) {
                $normalisations[] = "\"{$concerneSaisi}\" → \"{$categorie->label()}\"";
            } elseif (! $categorie) {
                $suggestion = ReferenceValueResolver::suggestClosest($concerneSaisi, CategorieDepense::cases(), fn (CategorieDepense $c) => $c->label());
                $valeursAutorisees = implode(', ', array_map(fn (CategorieDepense $c) => $c->label(), CategorieDepense::cases()));
                $erreurs[] = $suggestion
                    ? "Ligne {$numeroLigne} — `concerne` : valeur \"{$concerneSaisi}\" invalide. Vouliez-vous dire \"{$suggestion}\" ? (valeurs autorisées : {$valeursAutorisees})"
                    : "Ligne {$numeroLigne} — `concerne` : valeur \"{$concerneSaisi}\" invalide (valeurs autorisées : {$valeursAutorisees}).";
            }
        }

        // ── Description (facultative) ───────────────────────────────────────
        $description = trim((string) ($ligne['description_facultatif'] ?? ''));
        if ($description !== '' && mb_strlen($description) > 500) {
            $erreurs[] = "Ligne {$numeroLigne} — `description_facultatif` : ne peut pas dépasser 500 caractères.";
        }

        // ── Commentaire obligatoire (oui/non, facultatif — défaut Non) ──────
        $commentaireBrut = trim((string) ($ligne['commentaire_obligatoire'] ?? ''));
        $commentaireObligatoire = false;
        if ($commentaireBrut !== '') {
            $bool = $this->resoudreBool($commentaireBrut);
            if ($bool === null) {
                $erreurs[] = "Ligne {$numeroLigne} — `commentaire_obligatoire` : \"{$commentaireBrut}\" invalide (oui/non attendu).";
            } else {
                $commentaireObligatoire = $bool;
            }
        }

        // ── Justificatif obligatoire (oui/non, facultatif — défaut Non) ─────
        $justificatifBrut = trim((string) ($ligne['justificatif_obligatoire'] ?? ''));
        $justificatifObligatoire = false;
        if ($justificatifBrut !== '') {
            $bool = $this->resoudreBool($justificatifBrut);
            if ($bool === null) {
                $erreurs[] = "Ligne {$numeroLigne} — `justificatif_obligatoire` : \"{$justificatifBrut}\" invalide (oui/non attendu).";
            } else {
                $justificatifObligatoire = $bool;
            }
        }

        // ── Statut (actif/inactif, facultatif — défaut Actif) ───────────────
        $statutBrut = trim((string) ($ligne['statut'] ?? ''));
        $isActive = true;
        if ($statutBrut !== '') {
            $v = mb_strtolower($statutBrut, 'UTF-8');
            if ($v === 'actif') {
                $isActive = true;
            } elseif ($v === 'inactif') {
                $isActive = false;
            } else {
                $erreurs[] = "Ligne {$numeroLigne} — `statut` : \"{$statutBrut}\" invalide (actif/inactif attendu).";
            }
        }

        if (! empty($erreurs)) {
            return $this->erreur($numeroLigne, $libelle, $erreurs, $normalisations);
        }

        return [
            'numero_ligne' => $numeroLigne,
            'libelle' => $libelle,
            'statut' => 'nouveau',
            'erreurs' => [],
            'normalisations' => $normalisations,
            'avertissements' => [],
            'data' => [
                'libelle' => $libelle,
                'code' => $code,
                'categorie' => $categorie->value,
                'categorie_label' => $categorie->label(),
                'description' => $description !== '' ? $description : null,
                'commentaire_obligatoire' => $commentaireObligatoire,
                'justificatif_obligatoire' => $justificatifObligatoire,
                'is_active' => $isActive,
            ],
        ];
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

    private function erreur(int $numeroLigne, ?string $libelle, array $erreurs, array $normalisations = []): array
    {
        return [
            'numero_ligne' => $numeroLigne,
            'libelle' => $libelle,
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
