<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { FileText, Pencil } from 'lucide-vue-next';

interface ModeleResume {
    type_contrat: string;
    type_contrat_label: string;
    titre: string;
    statut: string;
    statut_label: string;
    enregistre: boolean;
    revision: number;
    articles_count: number;
    modifie_le: string | null;
    modifie_par: string | null;
}

defineProps<{ modeles: ModeleResume[] }>();

useFlashToast();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Modèles de contrat', href: '/backoffice/modeles-contrat' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :hide-mobile-header="true">
        <Head title="Modèles de contrat" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Modèles de contrat"
                    description="Le texte des contrats de travail générés depuis RH → Contrats : un modèle pour les CDI, un pour les CDD."
                />

                <p
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
                >
                    La trame proposée par l’application est une structure, pas
                    un texte juridique validé. Faites relire et adapter chaque
                    modèle au droit applicable avant de l’activer : aucun
                    contrat ne peut être généré avec un modèle en brouillon.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="modele in modeles"
                        :key="modele.type_contrat"
                        class="flex flex-col gap-4 rounded-xl border bg-card p-5"
                        :data-testid="`modele-contrat-${modele.type_contrat}`"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <FileText
                                    class="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground"
                                />
                                <div>
                                    <p class="font-medium">
                                        {{ modele.type_contrat_label }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ modele.titre }}
                                    </p>
                                </div>
                            </div>
                            <StatusDot
                                :status="modele.statut"
                                :label="modele.statut_label"
                            />
                        </div>

                        <p class="text-xs text-muted-foreground">
                            {{ modele.articles_count }} article{{
                                modele.articles_count > 1 ? 's' : ''
                            }}
                            <template v-if="modele.enregistre">
                                · révision {{ modele.revision }}
                                <template v-if="modele.modifie_le">
                                    · modifié le {{ modele.modifie_le }}
                                    <template v-if="modele.modifie_par">
                                        par {{ modele.modifie_par }}
                                    </template>
                                </template>
                            </template>
                            <template v-else>
                                · trame de l’application, pas encore enregistrée
                            </template>
                        </p>

                        <div class="mt-auto">
                            <Link
                                :href="`/backoffice/modeles-contrat/${modele.type_contrat}/edit`"
                            >
                                <Button size="sm" variant="outline">
                                    <Pencil class="mr-2 h-4 w-4" />
                                    Relire et modifier
                                </Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
