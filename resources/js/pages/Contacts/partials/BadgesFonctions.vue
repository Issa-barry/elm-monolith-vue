<script setup lang="ts">
/**
 * Fonctions d'un contact sous forme de badges de catégorie (ce ne sont pas des statuts : fond
 * coloré autorisé). Le serveur n'envoie que les fonctions lisibles par l'utilisateur.
 */
import type { CodeFonction, FonctionContact } from './types';

defineProps<{ fonctions: FonctionContact[] }>();

const COULEURS: Record<CodeFonction, string> = {
    client: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    fournisseur:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    prestataire:
        'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    proprietaire:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    parrain: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    livreur: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    employe:
        'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
    interlocuteur:
        'bg-teal-50 text-teal-700 dark:bg-teal-950/40 dark:text-teal-300',
};

function texte(f: FonctionContact): string {
    return f.sous_categorie_libelle
        ? `${f.libelle} · ${f.sous_categorie_libelle}`
        : f.libelle;
}
</script>

<template>
    <div class="flex flex-wrap gap-1.5">
        <span
            v-for="f in fonctions"
            :key="`${f.fonction}-${f.id}`"
            class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap"
            :class="[
                COULEURS[f.fonction],
                f.actif === false ? 'opacity-60' : '',
            ]"
            :title="f.actif === false ? `${texte(f)} (inactif)` : texte(f)"
            data-testid="contact-fonction"
        >
            {{ texte(f) }}
        </span>
        <span
            v-if="fonctions.length === 0"
            class="text-xs text-muted-foreground"
            >—</span
        >
    </div>
</template>
