#!/usr/bin/env node
/**
 * Vérifie le respect de deux standards UI internes que les outils de lint
 * généralistes (ESLint/Prettier) ne couvrent pas :
 *
 *  1. Statut affiché avec un badge à fond coloré (pill) au lieu du composant
 *     `StatusDot.vue` (point coloré + texte simple).
 *  2. Page avec plusieurs filtres "faits maison" (refs `filterXxx`) sans
 *     utiliser le composant standard `DataFilters.vue`.
 *
 * Pourquoi un script dédié plutôt qu'une règle ESLint custom : ESLint analyse
 * du JS/TS, pas des classes Tailwind dans un template Vue. Une règle AST
 * serait plus lourde à écrire/maintenir qu'un scan ciblé par fenêtre de
 * lignes, pour un gain équivalent.
 *
 * Échappatoire volontaire : un commentaire `ui-standard-ignore-file` n'importe
 * où dans le fichier désactive les deux checks pour ce fichier (cas
 * légitimes : catégorie/rôle/type, pas un statut).
 */

import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const SCAN_DIRS = ['resources/js/pages', 'resources/js/components'];
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

function main() {
    /** @type {{file: string, line: number, type: 'badge'|'filter', detail: string}[]} */
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
        console.log('✓ Standards UI (StatusDot / DataFilters) respectés.');
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
            'ajoute un commentaire `ui-standard-ignore-file` en haut du fichier pour désactiver ce check.',
    );
    process.exitCode = 1;
}

main();
