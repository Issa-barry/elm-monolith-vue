<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Building2, HandCoins, ShieldCheck } from 'lucide-vue-next';
import RadioButton from 'primevue/radiobutton';
import { useToast } from 'primevue/usetoast';
import { computed, reactive, ref } from 'vue';

interface DeclencheurOption {
    value: string;
    label: string;
}

interface SiteRow {
    id: string;
    label: string;
    type_label: string;
    approbation_reception_logistique_obligatoire: boolean | null;
}

const props = defineProps<{
    declencheur_commission_logistique: string;
    approbation_reception_logistique_obligatoire: boolean;
    declencheurs_commission_logistique_options: DeclencheurOption[];
    sites: SiteRow[];
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

// Description par option — présentation uniquement, l'énumération backend
// (DeclencheurCommissionLogistique) ne porte que value/label.
const declencheurDescriptions: Record<string, string> = {
    chargement_valide:
        'La commission est générée dès le départ du transfert, sur la base de la quantité chargée.',
    reception_effectuee:
        'La commission est générée lorsque le transfert est réceptionné, sur la base de la quantité reçue.',
};

// ── Dérogation par site ────────────────────────────────────────────────────
// Copie locale mutable : chaque ligne s'enregistre elle-même (pas de
// soumission groupée via `form` ci-dessus), donc jamais de mutation directe
// des props.
const siteRows = reactive<SiteRow[]>(props.sites.map((s) => ({ ...s })));
const savingSiteId = ref<string | null>(null);

type ApprobationChoix = 'herite' | 'obligatoire' | 'non_requise';

function choixDuSite(site: SiteRow): ApprobationChoix {
    if (site.approbation_reception_logistique_obligatoire === null) {
        return 'herite';
    }

    return site.approbation_reception_logistique_obligatoire
        ? 'obligatoire'
        : 'non_requise';
}

function onSiteChange(site: SiteRow, e: Event) {
    const choix = (e.target as HTMLSelectElement).value as ApprobationChoix;
    const valeur =
        choix === 'herite' ? null : choix === 'obligatoire' ? true : false;

    savingSiteId.value = site.id;
    router.patch(
        `/settings/logistique/sites/${site.id}`,
        { approbation_reception_logistique_obligatoire: valeur },
        {
            preserveScroll: true,
            onSuccess: () => {
                site.approbation_reception_logistique_obligatoire = valeur;
                toast.add({
                    severity: 'success',
                    summary: `${site.label} mis à jour`,
                    life: 2000,
                });
            },
            onFinish: () => {
                savingSiteId.value = null;
            },
        },
    );
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

                <!-- ══ 1. Quand la commission devient due ═══════════════════ -->
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <HandCoins class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Commission logistique
                        </h3>
                    </div>

                    <div class="space-y-3 px-5 py-4">
                        <p class="text-sm font-medium text-foreground">
                            Quand la commission devient-elle due ?
                        </p>

                        <label
                            v-for="option in declencheurs_commission_logistique_options"
                            :key="`logistique-${option.value}`"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                            :class="
                                form.declencheur_commission_logistique ===
                                option.value
                                    ? 'border-primary bg-primary/5'
                                    : 'border-transparent hover:bg-muted/30'
                            "
                        >
                            <RadioButton
                                :model-value="
                                    form.declencheur_commission_logistique
                                "
                                :value="option.value"
                                :disabled="form.processing"
                                class="mt-0.5"
                                @update:model-value="
                                    form.declencheur_commission_logistique =
                                        option.value
                                "
                            />
                            <div>
                                <p
                                    class="text-sm font-medium text-foreground"
                                >
                                    {{ option.label }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ declencheurDescriptions[option.value] }}
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- ══ 2. Approbation des réceptions ═════════════════════════ -->
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <ShieldCheck class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Approbation des réceptions
                        </h3>
                    </div>

                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="text-sm font-medium text-foreground"
                                >
                                    Une approbation administrative est-elle
                                    obligatoire ?
                                </p>
                                <p class="mt-1 flex items-center gap-2">
                                    <span
                                        class="text-xs font-semibold"
                                        :class="
                                            form.approbation_reception_logistique_obligatoire
                                                ? 'text-primary'
                                                : 'text-muted-foreground'
                                        "
                                        >{{
                                            form.approbation_reception_logistique_obligatoire
                                                ? 'Activée'
                                                : 'Désactivée'
                                        }}</span
                                    >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >— règle par défaut de
                                        l'organisation</span
                                    >
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

                        <p
                            class="mt-3 rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
                        >
                            <template
                                v-if="
                                    form.approbation_reception_logistique_obligatoire
                                "
                            >
                                Un administrateur doit approuver chaque
                                réception avant que la commission logistique
                                ne soit générée.
                            </template>
                            <template v-else>
                                Les réceptions sont approuvées
                                automatiquement dès qu'elles sont
                                enregistrées : la commission logistique part
                                aussitôt, sans intervention d'un
                                administrateur.
                            </template>
                            Sans effet si le déclencheur ci-dessus est réglé
                            sur « à la validation du chargement » : la
                            commission naît alors déjà au départ du
                            transfert, avant même la réception.
                        </p>
                    </div>
                </div>

                <!-- ══ 3. Dérogation par site ═════════════════════════════════ -->
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Building2 class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Dérogation par site
                        </h3>
                    </div>

                    <div class="space-y-1 px-5 py-3">
                        <p class="text-xs text-muted-foreground">
                            Par défaut, tous les sites suivent la règle de
                            l'organisation ci-dessus (<span
                                class="font-medium text-foreground"
                                >{{
                                    form.approbation_reception_logistique_obligatoire
                                        ? 'approbation activée'
                                        : 'approbation désactivée'
                                }}</span
                            >). Vous pouvez définir une règle différente pour
                            un site en particulier — la règle appliquée à un
                            transfert est celle de son site
                            <span class="font-medium text-foreground"
                                >destination</span
                            >.
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Changer la règle d'un site n'affecte jamais un
                            transfert déjà réceptionné en attente
                            d'approbation — seules les prochaines réceptions
                            suivent la nouvelle règle.
                        </p>
                    </div>

                    <div class="divide-y">
                        <div
                            v-if="siteRows.length === 0"
                            class="px-5 py-6 text-center text-sm text-muted-foreground"
                        >
                            Aucun site configuré.
                        </div>

                        <div
                            v-for="site in siteRows"
                            :key="site.id"
                            class="flex items-center justify-between gap-4 px-5 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-foreground">
                                    {{ site.label }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ site.type_label }}
                                </p>
                            </div>

                            <select
                                :value="choixDuSite(site)"
                                :disabled="savingSiteId === site.id"
                                :aria-label="`Approbation de réception pour ${site.label}`"
                                class="w-64 rounded-md border bg-background px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                @change="onSiteChange(site, $event)"
                            >
                                <option value="herite">
                                    Hérite de l'organisation ({{
                                        form.approbation_reception_logistique_obligatoire
                                            ? 'activée'
                                            : 'désactivée'
                                    }})
                                </option>
                                <option value="obligatoire">
                                    Approbation obligatoire
                                </option>
                                <option value="non_requise">
                                    Approbation non requise
                                </option>
                            </select>
                        </div>
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
