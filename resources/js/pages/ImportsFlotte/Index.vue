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
    nb_groupes_valides: number;
    nb_groupes_erreur: number;
    nb_vehicules_crees: number | null;
    utilisateur: string | null;
    created_at: string | null;
}

defineProps<{ imports: ImportRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/parametres' },
    { title: 'Import flotte', href: '/settings/imports-flotte' },
];

function ouvrir(id: string) {
    router.visit(`/settings/imports-flotte/${id}`);
}
</script>

<template>
    <Head title="Import flotte - Historique" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">
                        Historique des imports
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Propriétaires, véhicules et livreurs importés en
                        masse.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link href="/settings/imports-flotte/nouveau">
                        <Button size="sm">
                            <Plus class="mr-1.5 h-4 w-4" />
                            Nouvel import
                        </Button>
                    </Link>
                    <Link href="/settings/parametres">
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
                            <th class="px-4 py-3 font-medium">Groupes</th>
                            <th class="px-4 py-3 font-medium">Véhicules créés</th>
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
                                {{ i.nb_groupes_valides }} valide(s)
                                <span v-if="i.nb_groupes_erreur">
                                    · {{ i.nb_groupes_erreur }} erreur(s)</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                {{ i.nb_vehicules_crees ?? '—' }}
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
