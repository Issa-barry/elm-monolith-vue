#!/usr/bin/env node
/**
 * Vérifie le respect de standards UI internes que les outils de lint
 * généralistes (ESLint/Prettier) ne couvrent pas :
 *
 *  1. Statut affiché avec un badge à fond coloré (pill) au lieu du composant
 *     `StatusDot.vue` (point coloré + texte simple).
 *  2. Page avec plusieurs filtres "faits maison" (refs `filterXxx`) sans
 *     utiliser le composant standard `DataFilters.vue`.
 *  2bis. Sur les pages de liste déjà migrées vers le standard "actions
 *     d'en-tête" (AGENTS.md §2) : import de `ListPageActions.vue` et usage de
 *     `DataFilters` en mode `trigger-only`. Allowlist positive
 *     (`LIST_PAGE_ACTIONS_REQUIRED`) plutôt qu'un ban général — la
 *     standardisation se fait page par page, une page absente de la liste
 *     n'est pas encore contrôlée et ne casse pas le CI.
 *  3. Toast PrimeVue placé ailleurs qu'en haut à droite.
 *
 * Pourquoi un script dédié plutôt qu'une règle ESLint custom : ESLint analyse
 * du JS/TS, pas des classes Tailwind dans un template Vue. Une règle AST
 * serait plus lourde à écrire/maintenir qu'un scan ciblé par fenêtre de
 * lignes, pour un gain équivalent.
 *
 * Échappatoire volontaire : un commentaire `ui-standard-ignore-file` n'importe
 * où dans le fichier désactive les checks Badge/DataFilters/ListPageActions
 * pour ce fichier (cas légitimes : catégorie/rôle/type, pas un statut). La
 * position des Toasts reste obligatoire et ne peut pas être ignorée.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const SCAN_DIRS = [
    'resources/js/pages',
    'resources/js/components',
    'resources/js/layouts',
];
const EXCLUDE_PATHS = [
    'resources/js/components/ui',
    'resources/js/components/StatusDot.vue',
    'resources/js/components/filters/DataFilters.vue',
];

const COLOR_BG_RE =
    /bg-(?:red|emerald|green|amber|orange|blue|teal|zinc|slate|violet|purple|yellow|indigo|cyan|rose|lime|sky|fuchsia|pink)-(?:50|100)\b/;
const ROUNDED_FULL_RE = /rounded-full/;
// "statut" doit être utilisé comme identifiant de code (statut_label,
// commande.statut], statut.value...), pas comme mot dans une phrase en dur
// ("Voir informations et statut") — sinon trop de faux positifs.
const STATUT_RE = /\bstatut(?=[_.[\])])/i;
const IGNORE_FILE_RE = /ui-standard-ignore-file/;
const DATAFILTERS_IMPORT_RE =
    /from\s+['"]@\/components\/filters\/DataFilters\.vue['"]/;
// Préfixe filter/filtre suivi d'une majuscule (camelCase) pour ne capturer que
// les filtres métier nommés (filterType, filtreAgence...), pas les noms
// internes de PrimeVue comme `globalFilter` ou `filtersMeta`.
const FILTER_REF_RE =
    /\b(?:const|let)\s+((?:filtre|[Ff]ilter)[A-Z]\w*)\s*=\s*ref\(/g;
const TOAST_TAG_RE = /<Toast\b[\s\S]*?>/g;
const TOAST_TOP_RIGHT_RE = /\bposition\s*=\s*["']top-right["']/;

const LIST_PAGE_ACTIONS_IMPORT_RE =
    /from\s+['"]@\/components\/ListPageActions\.vue['"]/;
const DATAFILTERS_TAG_RE = /<DataFilters\b/g;
const TRIGGER_ONLY_RE = /\btrigger-only\b|:trigger-only\s*=/;

// Phase 1 de la standardisation des pages de liste (AGENTS.md §2) : chemins
// des pages déjà migrées vers <ListPageActions> + <DataFilters trigger-only>.
// Allowlist volontairement positive (pas un ban général sur toutes les pages
// utilisant DataFilters) pour que le check reste adoptable pendant que les
// pages restantes sont migrées progressivement — ajoute un chemin ici dès
// qu'une page est migrée, ne retire jamais une page de cette liste après coup.
const LIST_PAGE_ACTIONS_REQUIRED = [
    'resources/js/pages/Sites/Index.vue',
    'resources/js/pages/Ventes/Index.vue',
    'resources/js/pages/Proprietaires/Index.vue',
    'resources/js/pages/Depenses/Types/Index.vue',
    'resources/js/pages/Produits/Index.vue',
    'resources/js/pages/Vehicules/Index.vue',
    'resources/js/components/commission/CommissionIndexLayout.vue',
    'resources/js/pages/Produits/Stock/Index.vue',
];

/** @returns {string[]} absolute paths of .vue files under dir */
function walkVueFiles(dir) {
    const out = [];
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) {
            out.push(...walkVueFiles(full));
        } else if (entry.isFile() && entry.name.endsWith('.vue')) {
            out.push(full);
        }
    }
    return out;
}

function isExcluded(relPath) {
    return EXCLUDE_PATHS.some(
        (p) => relPath === p || relPath.startsWith(p + '/'),
    );
}

const OPEN_TAG_RE = /<(span|Badge|button)\b/;

/**
 * Cherche, pour chaque balise span/Badge/button, si SON propre contenu
 * (jusqu'à sa balise fermante, pas celle d'un voisin) combine un fond
 * coloré + "statut" — pour éviter de capturer un <StatusDot> adjacent qui
 * référence aussi `statut_label`.
 */
function checkColoredBadge(lines) {
    const hits = [];
    for (let i = 0; i < lines.length; i++) {
        const tagMatch = lines[i].match(OPEN_TAG_RE);
        if (!tagMatch) continue;
        const tag = tagMatch[1];
        const closeRe = new RegExp(`</${tag}>`);
        let end = i;
        while (
            end < lines.length &&
            end < i + 25 &&
            !closeRe.test(lines[end])
        ) {
            end++;
        }
        const block = lines.slice(i, end + 1).join('\n');
        if (
            ROUNDED_FULL_RE.test(block) &&
            COLOR_BG_RE.test(block) &&
            STATUT_RE.test(block)
        ) {
            hits.push(i + 1);
        }
    }
    return hits;
}

function checkHomemadeFilters(content, lines) {
    if (DATAFILTERS_IMPORT_RE.test(content)) return null;
    const names = [...content.matchAll(FILTER_REF_RE)].map((m) => m[1]);
    if (names.length < 2) return null;
    const firstLine =
        lines.findIndex((l) => l.includes(`${names[0]} = ref(`)) + 1;
    return { line: firstLine || 1, names };
}

/**
 * @returns {{line: number, detail: string}[]} violations pour une page listée
 * dans LIST_PAGE_ACTIONS_REQUIRED : import manquant de ListPageActions.vue, ou
 * un <DataFilters> sans trigger-only (grosse barre manuelle refusée).
 */
function checkListPageActions(relPath, content) {
    if (!LIST_PAGE_ACTIONS_REQUIRED.includes(relPath)) return [];

    const hits = [];

    if (!LIST_PAGE_ACTIONS_IMPORT_RE.test(content)) {
        hits.push({
            line: 1,
            detail: "Page migrée vers le standard de liste (AGENTS.md §2) : elle doit importer et utiliser <ListPageActions> pour ses actions d'en-tête (Exporter/Importer/Filtres/Nouveau).",
        });
    }

    for (const match of content.matchAll(DATAFILTERS_TAG_RE)) {
        const tagEnd = content.indexOf('>', match.index);
        const tag = content.slice(
            match.index,
            tagEnd === -1 ? match.index + 200 : tagEnd + 1,
        );
        if (!TRIGGER_ONLY_RE.test(tag)) {
            hits.push({
                line: content.slice(0, match.index).split('\n').length,
                detail: 'DataFilters doit être utilisé en mode trigger-only sur cette page migrée (une grosse barre de filtres manuelle au-dessus du tableau est refusée, cf. AGENTS.md §2).',
            });
        }
    }

    return hits;
}

function checkToastPositions(content) {
    const hits = [];
    for (const match of content.matchAll(TOAST_TAG_RE)) {
        if (TOAST_TOP_RIGHT_RE.test(match[0])) continue;
        const line = content.slice(0, match.index).split('\n').length;
        hits.push(line);
    }
    return hits;
}

function main() {
    /** @type {{file: string, line: number, type: 'badge'|'filter'|'toast', detail: string}[]} */
    const violations = [];

    for (const dir of SCAN_DIRS) {
        const absDir = join(ROOT, dir);
        let files;
        try {
            files = walkVueFiles(absDir);
        } catch {
            continue;
        }

        for (const file of files) {
            const relPath = relative(ROOT, file).replaceAll('\\', '/');
            if (isExcluded(relPath)) continue;

            const content = readFileSync(file, 'utf8');

            for (const line of checkToastPositions(content)) {
                violations.push({
                    file: relPath,
                    line,
                    type: 'toast',
                    detail: 'Toast hors standard détecté. Tout <Toast> PrimeVue doit déclarer explicitement position="top-right".',
                });
            }

            if (IGNORE_FILE_RE.test(content)) continue;

            const lines = content.split('\n');

            for (const line of checkColoredBadge(lines)) {
                violations.push({
                    file: relPath,
                    line,
                    type: 'badge',
                    detail: 'Badge de statut à fond coloré détecté. Utilise <StatusDot :status="..." :label="..." /> (point coloré + texte) au lieu d\'un span/Badge avec classes bg-*-50/100 + rounded-full.',
                });
            }

            for (const hit of checkListPageActions(relPath, content)) {
                violations.push({
                    file: relPath,
                    line: hit.line,
                    type: 'list-page-actions',
                    detail: hit.detail,
                });
            }

            if (relPath.startsWith('resources/js/pages/')) {
                const filterHit = checkHomemadeFilters(content, lines);
                if (filterHit) {
                    violations.push({
                        file: relPath,
                        line: filterHit.line,
                        type: 'filter',
                        detail: `Filtres faits maison détectés (${filterHit.names.join(', ')}) sans import de DataFilters.vue. Utilise <DataFilters :fields="..." /> (ordre Filtres/Recherche/Agence, Agence via site_ids[]).`,
                    });
                }
            }
        }
    }

    if (violations.length === 0) {
        console.log(
            '✓ Standards UI (StatusDot / DataFilters / ListPageActions / Toast top-right) respectés.',
        );
        return;
    }

    console.error(
        `✗ ${violations.length} violation(s) des standards UI internes :\n`,
    );
    for (const v of violations) {
        console.error(`  ${v.file}:${v.line} [${v.type}]\n    ${v.detail}\n`);
    }
    console.error(
        "Si le badge/filtre n'est volontairement PAS un statut/filtre (ex: catégorie, rôle, type), " +
            'ajoute un commentaire `ui-standard-ignore-file` en haut du fichier. La règle Toast top-right ne peut pas être ignorée.',
    );
    process.exitCode = 1;
}

main();
