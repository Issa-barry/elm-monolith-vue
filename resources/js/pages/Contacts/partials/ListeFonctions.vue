<script setup lang="ts">
/**
 * Fonctions d'un contact, une ligne par fonction, avec un lien vers l'écran métier existant quand
 * il en existe un (le serveur ne l'envoie que pour une fonction lisible).
 */
import StatusDot from '@/components/StatusDot.vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import type { FonctionContact } from './types';

defineProps<{ fonctions: FonctionContact[] }>();
</script>

<template>
    <ul class="divide-y rounded-xl border bg-card">
        <li
            v-for="f in fonctions"
            :key="`${f.fonction}-${f.id}`"
            class="flex items-center justify-between gap-3 px-4 py-3"
            data-testid="contact-fonction-ligne"
        >
            <div class="min-w-0">
                <p class="text-sm font-medium">
                    {{ f.libelle }}
                    <template v-if="f.au_nom_de">
                        <span class="font-normal text-muted-foreground">
                            au nom de
                        </span>
                        <Link
                            v-if="f.entreprise_lien"
                            :href="f.entreprise_lien"
                            class="text-primary hover:underline"
                            data-testid="contact-fonction-au-nom-de"
                            >{{ f.au_nom_de }}</Link
                        >
                        <span v-else>{{ f.au_nom_de }}</span>
                    </template>
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{
                        [f.sous_categorie_libelle, f.reference]
                            .filter(Boolean)
                            .join(' · ') || '—'
                    }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <StatusDot
                    v-if="f.actif !== null"
                    class="text-xs text-muted-foreground"
                    :status="f.actif ? 'actif' : 'inactif'"
                    :label="f.actif ? 'Actif' : 'Inactif'"
                />
                <Link
                    v-if="f.lien"
                    :href="f.lien"
                    class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                    Ouvrir
                    <ChevronRight class="h-3.5 w-3.5" />
                </Link>
            </div>
        </li>
        <li
            v-if="fonctions.length === 0"
            class="px-4 py-8 text-center text-sm text-muted-foreground"
        >
            Aucune fonction visible.
        </li>
    </ul>
</template>
