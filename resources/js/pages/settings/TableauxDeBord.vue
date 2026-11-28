<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { LayoutGrid } from 'lucide-vue-next';
import ToggleSwitch from 'primevue/toggleswitch';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

/** Un tableau de bord « Stat- » et son activation pour l'organisation (ADR 0071). */
interface TableauDeBord {
    cle: string;
    libelle: string;
    description: string;
    actif: boolean;
}

defineProps<{ tableaux: TableauDeBord[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Tableaux de bord', href: '/settings/tableaux-de-bord' },
];

const toast = useToast();
// Un enregistrement à la fois par tableau : interrupteur verrouillé pendant la requête.
const enCours = ref<Record<string, boolean>>({});

function basculer(tableau: TableauDeBord, actif: boolean) {
    if (enCours.value[tableau.cle]) return;
    enCours.value[tableau.cle] = true;

    router.patch(
        '/settings/tableaux-de-bord',
        { tableau: tableau.cle, actif },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.add({
                    group: 'top',
                    severity: 'success',
                    summary: 'Succès',
                    detail: `${tableau.libelle} ${actif ? 'activé' : 'désactivé'}.`,
                    life: 3000,
                });
            },
            onError: () => {
                toast.add({
                    group: 'top',
                    severity: 'error',
                    summary: 'Erreur',
                    detail: `${tableau.libelle} n'a pas pu être modifié.`,
                    life: 5000,
                });
            },
            onFinish: () => {
                enCours.value[tableau.cle] = false;
            },
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head><title>Tableaux de bord</title></Head>

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Tableaux de bord"
                    description="Choisissez les tableaux de bord proposés dans le menu. Un tableau désactivé disparaît du menu et n'est plus accessible, même par un lien direct. Les permissions de chaque utilisateur continuent de s'appliquer."
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <LayoutGrid class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Tableaux de bord disponibles
                        </h3>
                    </div>

                    <div class="divide-y">
                        <div
                            v-for="tableau in tableaux"
                            :key="tableau.cle"
                            class="flex items-center gap-4 px-5 py-4"
                            :data-testid="`tableau-${tableau.cle}`"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-foreground">
                                    {{ tableau.libelle }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ tableau.description }}
                                </p>
                            </div>

                            <StatusDot
                                class="hidden shrink-0 sm:inline-flex"
                                :status="tableau.actif ? 'actif' : 'inactif'"
                                :label="tableau.actif ? 'Activé' : 'Désactivé'"
                            />

                            <ToggleSwitch
                                :model-value="tableau.actif"
                                :disabled="enCours[tableau.cle]"
                                :aria-label="`${tableau.actif ? 'Désactiver' : 'Activer'} ${tableau.libelle}`"
                                @update:model-value="
                                    (valeur: boolean) =>
                                        basculer(tableau, valeur)
                                "
                            />
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
