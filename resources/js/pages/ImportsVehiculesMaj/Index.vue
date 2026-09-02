<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Upload } from 'lucide-vue-next';

interface ImportRow {
    id: string;
    fichier_original: string;
    statut: string;
    statut_label: string;
    nb_lignes_maj: number;
    nb_lignes_inchange: number;
    nb_lignes_erreur: number;
    nb_vehicules_mis_a_jour: number | null;
    utilisateur: string | null;
    created_at: string | null;
}

defineProps<{ imports: ImportRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Véhicules', href: '/backoffice/vehicules' },
    {
        title: 'Mise à jour en masse',
        href: '/backoffice/vehicules/imports-maj',
    },
];

function ouvrir(id: string) {
    router.visit(`/backoffice/vehicules/imports-maj/${id}`);
}
</script>

<template>
    <Head title="Véhicules - Historique des mises à jour" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">
                        Historique des mises à jour
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Imports de mise à jour en masse des véhicules (site,
                        capacités, usages).
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link href="/backoffice/vehicules/imports-maj/nouveau">
                        <Button size="sm">
                            <Plus class="mr-1.5 h-4 w-4" />
                            Nouvel import
                        </Button>
                    </Link>
                    <Link href="/backoffice/vehicules">
                        <Button size="sm" variant="outline">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                </div>
            </div>

            <div
                v-if="imports.length === 0"
                class="rounded-lg border border-dashed py-12 text-center"
            >
                <Upload class="mx-auto h-10 w-10 text-muted-foreground/30" />
                <p class="mt-3 text-sm text-muted-foreground">
                    Aucun import effectué pour le moment.
                </p>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/30 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Fichier</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3 font-medium">Lignes</th>
                            <th class="px-4 py-3 font-medium">
                                Véhicules mis à jour
                            </th>
                            <th class="px-4 py-3 font-medium">Utilisateur</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="i in imports"
                            :key="i.id"
                            class="cursor-pointer hover:bg-muted/20"
                            @click="ouvrir(i.id)"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ i.fichier_original }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="i.statut"
                                    :label="i.statut_label"
                                />
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ i.nb_lignes_maj }} à mettre à jour
                                <span v-if="i.nb_lignes_erreur">
                                    · {{ i.nb_lignes_erreur }} erreur(s)</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                {{ i.nb_vehicules_mis_a_jour ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ i.utilisateur ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ i.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
