<script setup lang="ts">
/**
 * Détail d'une fusion (ADR 0060, lot 9b) : arbitrages, journal complet et annulation sûre — ce qui
 * sera restauré, et ce qui demande une intervention (jamais écrasé).
 */
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { GitMerge, Loader2, Undo2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface Ligne {
    id: string;
    libelle: string;
    motif: string | null;
}

interface Operation {
    id: string;
    nature: string;
    libelle: string;
    valeur_avant: string | null;
    valeur_apres: string | null;
    annulation_statut: 'restauree' | 'conflit' | null;
}

const props = defineProps<{
    fusion: {
        id: string;
        type: 'personne' | 'entreprise';
        statut: string;
        statut_label: string;
        date: string | null;
        acteur: string | null;
        annulee_le: string | null;
        annulee_par: string | null;
        annulation: { restaurees: number; interventions: Ligne[] } | null;
        simulation: {
            a: { id: string; nom: string; lien: string };
            b: { id: string; nom: string; lien: string };
            champs: {
                cle: string;
                libelle: string;
                valeur_a: string | null;
                valeur_b: string | null;
                different: boolean;
                choix: 'a' | 'b';
            }[];
        };
    };
    operations: Operation[];
    analyse_annulation: {
        possible: boolean;
        motif: string | null;
        restaurables: Ligne[];
        interventions: Ligne[];
    } | null;
}>();

useFlashToast();
const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Historique des fusions', href: '/backoffice/contacts/fusions' },
    { title: 'Fusion', href: '#' },
];

const arbitrages = computed(() =>
    props.fusion.simulation.champs.filter((c) => c.different),
);
const NATURES: Record<string, string> = {
    champ: 'Champ',
    lien: 'Lien transféré',
    induit: 'Effet induit',
    archivage: 'Archivage',
    laisse: 'Laissé tel quel',
};
// Les liens transférés stockent des identifiants : on les nomme.
function valeur(v: string | null): string {
    if (v === null) return '—';
    if (v === props.fusion.simulation.a.id)
        return `Fiche A (${props.fusion.simulation.a.nom})`;
    if (v === props.fusion.simulation.b.id)
        return `Fiche B (${props.fusion.simulation.b.nom})`;
    return v;
}

const STATUTS_ANNULATION: Record<string, string> = {
    restauree: 'Restauré',
    conflit: 'À traiter',
};

const form = useForm({});
const dialogue = ref(false);
function fermer(visible: boolean) {
    if (!form.processing) dialogue.value = visible;
}
function annuler() {
    if (form.processing) return;
    form.post(`/backoffice/contacts/fusions/${props.fusion.id}/annulation`, {
        preserveScroll: true,
        onFinish: () => (dialogue.value = false),
    });
}
</script>

<template>
    <Head title="Fusion" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Fusion"
                :title="`${fusion.simulation.b.nom} → ${fusion.simulation.a.nom}`"
                :icon="GitMerge"
            >
                <template #subtitle>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                    >
                        <StatusDot
                            :status="fusion.statut"
                            :label="fusion.statut_label"
                        />
                        <span
                            >{{ fusion.date }} ·
                            {{ fusion.acteur ?? '—' }}</span
                        >
                        <span v-if="fusion.annulee_le">
                            · annulée le {{ fusion.annulee_le }} par
                            {{ fusion.annulee_par ?? '—' }}</span
                        >
                    </div>
                </template>
                <template #actions>
                    <Link :href="fusion.simulation.a.lien">
                        <Button variant="outline" size="sm"
                            >Fiche conservée</Button
                        >
                    </Link>
                </template>
            </DetailHeader>

            <!-- Annulation -->
            <section
                v-if="analyse_annulation && can('contacts.fusionner')"
                class="space-y-3 rounded-xl border bg-card p-4 sm:p-6"
                data-testid="fusion-annulation"
            >
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Annulation
                </h2>
                <p
                    v-if="!analyse_annulation.possible"
                    class="text-sm text-destructive"
                >
                    {{ analyse_annulation.motif }}
                </p>
                <template v-else>
                    <p class="text-sm">
                        {{ analyse_annulation.restaurables.length }} élément(s)
                        seront restaurés.
                    </p>
                    <div
                        v-if="analyse_annulation.interventions.length > 0"
                        class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                    >
                        <p class="font-medium">
                            Non restaurés (intervention nécessaire) :
                        </p>
                        <ul class="mt-1 list-disc pl-5">
                            <li
                                v-for="l in analyse_annulation.interventions"
                                :key="l.id"
                            >
                                {{ l.libelle }} — {{ l.motif }}
                            </li>
                        </ul>
                    </div>
                    <Button
                        variant="outline"
                        data-testid="fusion-annuler"
                        @click="dialogue = true"
                    >
                        <Undo2 class="mr-1.5 h-4 w-4" />
                        Annuler la fusion
                    </Button>
                </template>
            </section>

            <section
                v-if="
                    fusion.annulation &&
                    fusion.annulation.interventions.length > 0
                "
                class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 sm:p-6 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <h2 class="font-semibold">
                    Éléments à traiter après l'annulation
                </h2>
                <ul class="list-disc pl-5">
                    <li
                        v-for="l in fusion.annulation.interventions"
                        :key="l.id"
                    >
                        {{ l.libelle }} — {{ l.motif }}
                    </li>
                </ul>
            </section>

            <!-- Arbitrages -->
            <section class="space-y-3 rounded-xl border bg-card p-4 sm:p-6">
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Arbitrages
                </h2>
                <p
                    v-if="arbitrages.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Aucun champ différent : les valeurs de la fiche conservée
                    sont restées.
                </p>
                <ul v-else class="space-y-1 text-sm">
                    <li v-for="c in arbitrages" :key="c.cle">
                        <span class="font-medium">{{ c.libelle }}</span> :
                        {{ (c.choix === 'a' ? c.valeur_a : c.valeur_b) ?? '—' }}
                        <span class="text-muted-foreground"
                            >(fiche {{ c.choix.toUpperCase() }})</span
                        >
                    </li>
                </ul>
            </section>

            <!-- Journal -->
            <section class="rounded-xl border bg-card">
                <h2
                    class="px-4 pt-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:px-6"
                >
                    Journal
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="fusion-journal">
                        <thead>
                            <tr
                                class="border-b text-left text-muted-foreground"
                            >
                                <th class="px-4 py-3 font-medium">Opération</th>
                                <th class="px-4 py-3 font-medium">Nature</th>
                                <th class="px-4 py-3 font-medium">Avant</th>
                                <th class="px-4 py-3 font-medium">Après</th>
                                <th class="px-4 py-3 font-medium">
                                    Annulation
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="o in operations"
                                :key="o.id"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-2">{{ o.libelle }}</td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    {{ NATURES[o.nature] ?? o.nature }}
                                </td>
                                <td class="px-4 py-2 break-all">
                                    {{ valeur(o.valeur_avant) }}
                                </td>
                                <td class="px-4 py-2 break-all">
                                    {{ valeur(o.valeur_apres) }}
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    {{
                                        o.annulation_statut
                                            ? STATUTS_ANNULATION[
                                                  o.annulation_statut
                                              ]
                                            : '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Dialog
            :visible="dialogue"
            modal
            header="Annuler la fusion"
            :closable="!form.processing"
            :close-on-escape="!form.processing"
            :draggable="false"
            :style="{ width: 'min(32rem, 94vw)' }"
            @update:visible="fermer"
        >
            <p class="text-sm">
                La fiche « {{ fusion.simulation.b.nom }} » sera restaurée avec
                ce qui n'a pas changé depuis. Les éléments modifiés depuis la
                fusion ne seront pas écrasés et resteront à traiter.
            </p>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="fermer(false)"
                >
                    Fermer
                </Button>
                <Button
                    :disabled="form.processing"
                    data-testid="fusion-annuler-confirmer"
                    @click="annuler"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    Annuler la fusion
                </Button>
            </template>
        </Dialog>
    </AppLayout>
</template>
