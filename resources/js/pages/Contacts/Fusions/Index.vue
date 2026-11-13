<script setup lang="ts">
/** Historique des fusions de fiches Contacts (ADR 0060, lot 9b). */
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { GitMerge } from 'lucide-vue-next';
import PaginationContacts from '../partials/PaginationContacts.vue';
import type { Pagination } from '../partials/types';

interface LigneFusion {
    id: string;
    type: 'personne' | 'entreprise';
    conservee: string;
    absorbee: string;
    acteur: string | null;
    date: string | null;
    statut: string;
    statut_label: string;
}

defineProps<{ fusions: Pagination<LigneFusion> }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Personnes physiques', href: '/backoffice/contacts/personnes' },
    { title: 'Historique des fusions', href: '#' },
];
</script>

<template>
    <Head title="Historique des fusions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Contacts"
                title="Historique des fusions"
                :icon="GitMerge"
            />

            <section class="rounded-xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="fusions-table">
                        <thead>
                            <tr
                                class="border-b text-left text-muted-foreground"
                            >
                                <th class="px-4 py-3 font-medium">Date</th>
                                <th class="px-4 py-3 font-medium">Type</th>
                                <th class="px-4 py-3 font-medium">
                                    Fiche conservée
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Fiche absorbée
                                </th>
                                <th class="px-4 py-3 font-medium">Par</th>
                                <th class="px-4 py-3 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="fusions.data.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    Aucune fusion.
                                </td>
                            </tr>
                            <tr
                                v-for="f in fusions.data"
                                :key="f.id"
                                class="border-b last:border-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3 tabular-nums">
                                    <Link
                                        :href="`/backoffice/contacts/fusions/${f.id}`"
                                        class="underline"
                                    >
                                        {{ f.date }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    {{
                                        f.type === 'personne'
                                            ? 'Personne'
                                            : 'Entreprise'
                                    }}
                                </td>
                                <td class="px-4 py-3">{{ f.conservee }}</td>
                                <td class="px-4 py-3">{{ f.absorbee }}</td>
                                <td class="px-4 py-3">{{ f.acteur ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :status="f.statut"
                                        :label="f.statut_label"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <PaginationContacts :page="fusions" />
        </div>
    </AppLayout>
</template>
