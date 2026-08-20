<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Download, Plus, Upload } from 'lucide-vue-next';

interface ImportRow {
    id: string;
    fichier_original: string;
    statut: string;
    statut_label: string;
    nb_lignes_total: number;
    nb_lignes_creation: number;
    nb_lignes_mise_a_jour: number;
    nb_lignes_inchange: number;
    nb_lignes_erreur: number;
    nb_produits_crees: number | null;
    nb_produits_mis_a_jour: number | null;
    utilisateur: string | null;
    created_at: string | null;
}

defineProps<{ imports: ImportRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Import de produits', href: '/backoffice/produits/imports' },
];

function ouvrir(id: string) {
    router.visit(`/backoffice/produits/imports/${id}`);
}
</script>

<template>
    <Head title="Import produits - Historique" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-lg font-semibold">
                        Historique des imports de produits
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Créations et mises à jour de produits en masse depuis
                        un fichier Excel.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link href="/backoffice/produits/imports/nouveau">
                        <Button size="sm">
                            <Plus class="mr-1.5 h-4 w-4" />
                            Nouvel import
                        </Button>
                    </Link>
                    <Link href="/backoffice/produits">
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
                            <th class="px-4 py-3 font-medium">Créés</th>
                            <th class="px-4 py-3 font-medium">
                                Mis à jour
                            </th>
                            <th class="px-4 py-3 font-medium">Inchangés</th>
                            <th class="px-4 py-3 font-medium">Erreurs</th>
                            <th class="px-4 py-3 font-medium">Utilisateur</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium" />
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
                            <td class="px-4 py-3">
                                {{ i.nb_produits_crees ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ i.nb_produits_mis_a_jour ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ i.nb_lignes_inchange }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="i.nb_lignes_erreur"
                                    class="text-destructive"
                                    >{{ i.nb_lignes_erreur }}</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >0</span
                                >
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ i.utilisateur ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ i.created_at }}
                            </td>
                            <td class="px-4 py-3" @click.stop>
                                <a
                                    v-if="i.statut === 'termine'"
                                    :href="`/backoffice/produits/imports/${i.id}/reprise`"
                                    class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                    title="Télécharger le fichier de reprise"
                                >
                                    <Download class="h-3.5 w-3.5" />
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
