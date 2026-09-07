<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { HandCoins } from 'lucide-vue-next';
import RadioButton from 'primevue/radiobutton';
import { useToast } from 'primevue/usetoast';
import { computed } from 'vue';

interface DeclencheurOption {
    value: string;
    label: string;
}

const props = defineProps<{
    declencheur_commission_logistique: string;
    approbation_reception_logistique_obligatoire: boolean;
    declencheurs_commission_logistique_options: DeclencheurOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Parametres', href: '/settings/profile' },
    { title: 'Parametrage logistique', href: '/settings/logistique' },
];

const toast = useToast();
const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as Record<string, string>)?.success ?? null,
);

const form = useForm({
    declencheur_commission_logistique: props.declencheur_commission_logistique,
    approbation_reception_logistique_obligatoire:
        props.approbation_reception_logistique_obligatoire,
});

function submit() {
    form.put('/settings/logistique', {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Paramétrage logistique mis à jour',
                life: 3000,
            });
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Parametrage logistique" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Parametrage logistique"
                    description="Configurez le workflow des transferts et de leur commission."
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <HandCoins class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Transferts logistiques
                        </h3>
                    </div>

                    <div class="space-y-2 px-5 py-4">
                        <p class="text-sm font-medium text-foreground">
                            Commission logistique — générer la commission
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Ce paramètre détermine à quel moment la commission
                            logistique devient générable.
                        </p>
                        <div
                            class="flex flex-col gap-3 pt-1 sm:flex-row sm:gap-6"
                        >
                            <label
                                v-for="option in declencheurs_commission_logistique_options"
                                :key="`logistique-${option.value}`"
                                class="flex cursor-pointer items-center gap-2"
                            >
                                <RadioButton
                                    :model-value="
                                        form.declencheur_commission_logistique
                                    "
                                    :value="option.value"
                                    :disabled="form.processing"
                                    @update:model-value="
                                        form.declencheur_commission_logistique =
                                            option.value
                                    "
                                />
                                <span class="text-sm">{{ option.label }}</span>
                            </label>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 border-t px-5 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-foreground">
                                Approbation admin de la réception obligatoire
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Si activé (par défaut), un administrateur doit
                                approuver chaque réception avant que la
                                commission logistique ne soit générée. Si
                                désactivé, la commission est générée
                                automatiquement dès que le transfert est
                                réceptionné, sans étape d'approbation. Sans
                                effet si le déclencheur ci-dessus est réglé sur
                                « chargement validé » : la commission naît
                                alors déjà au départ du transfert.
                            </p>
                        </div>

                        <button
                            type="button"
                            role="switch"
                            :aria-checked="
                                form.approbation_reception_logistique_obligatoire
                            "
                            :disabled="form.processing"
                            class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            :class="
                                form.approbation_reception_logistique_obligatoire
                                    ? 'bg-primary'
                                    : 'bg-input'
                            "
                            @click="
                                form.approbation_reception_logistique_obligatoire =
                                    !form.approbation_reception_logistique_obligatoire
                            "
                        >
                            <span
                                class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                :class="
                                    form.approbation_reception_logistique_obligatoire
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            />
                        </button>
                    </div>
                </div>

                <div
                    v-if="flashSuccess"
                    class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                >
                    {{ flashSuccess }}
                </div>

                <div class="flex justify-end">
                    <Button
                        :disabled="form.processing || !form.isDirty"
                        @click="submit"
                    >
                        Enregistrer
                    </Button>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
