<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Check, Save } from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

type Perimetre = 'toutes_agences' | 'son_agence' | 'agences_selectionnees';

interface RegleRole {
    role_name: string;
    role_label: string;
    a_permission: boolean;
    actif: boolean;
    plafond: number | null;
    plafond_illimite: boolean;
    perimetre: Perimetre;
    sites: string[];
}

const props = defineProps<{
    config: RegleRole[];
    sites: { id: string; nom: string; code: string | null }[];
}>();

const toast = useToast();

const lignes = ref<RegleRole[]>(
    props.config.map((c) => ({ ...c, sites: [...c.sites] })),
);

const PERIMETRES: { value: Perimetre; label: string }[] = [
    { value: 'toutes_agences', label: 'Toutes les agences' },
    { value: 'son_agence', label: 'Son agence uniquement' },
    { value: 'agences_selectionnees', label: 'Agences sélectionnées' },
];

function formatMontant(val: number | null): string {
    return val !== null ? new Intl.NumberFormat('fr-FR').format(val) : '';
}

const affichage = ref<Record<string, string>>(
    Object.fromEntries(
        lignes.value.map((l) => [l.role_name, formatMontant(l.plafond)]),
    ),
);

function onPlafondInput(l: RegleRole, e: Event) {
    const brut = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    l.plafond = brut ? parseInt(brut, 10) : null;
    affichage.value[l.role_name] = brut;
}

function onPlafondBlur(l: RegleRole) {
    affichage.value[l.role_name] = formatMontant(l.plafond);
}

function toggleSite(l: RegleRole, siteId: string) {
    const i = l.sites.indexOf(siteId);
    if (i === -1) l.sites.push(siteId);
    else l.sites.splice(i, 1);
}

function resumeValidation(l: RegleRole): string {
    if (l.plafond_illimite) return 'Valide sans limite';
    if (l.plafond === null) return 'Ne valide aucun bon';
    return `Valide jusqu'à ${formatMontant(l.plafond)} GNF`;
}

const enregistrement = ref(false);
const erreurs = ref<Record<string, string>>({});

function erreur(index: number, champ: string): string | undefined {
    return erreurs.value[`config.${index}.${champ}`];
}

function enregistrer() {
    enregistrement.value = true;
    erreurs.value = {};
    router.put(
        '/settings/achats/validation',
        {
            config: lignes.value.map((l) => ({
                role_name: l.role_name,
                actif: l.actif,
                plafond: l.plafond_illimite ? null : l.plafond,
                plafond_illimite: l.plafond_illimite,
                perimetre: l.perimetre,
                sites: l.perimetre === 'agences_selectionnees' ? l.sites : [],
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Paramètres des achats enregistrés',
                    life: 3000,
                }),
            onError: (e) => {
                erreurs.value = e;
                toast.add({
                    severity: 'error',
                    summary: 'Enregistrement impossible',
                    detail: Object.values(e)[0],
                    life: 6000,
                });
            },
            onFinish: () => (enregistrement.value = false),
        },
    );
}
</script>

<template>
    <Head title="Achats — périmètre et plafonds" />

    <AppLayout>
        <SettingsLayout :wide="true">
            <div class="space-y-6">
                <HeadingSmall
                    title="Achats — périmètre et plafonds"
                    description="Pour chaque rôle : les agences pour lesquelles il peut acheter (créer, voir et valider des bons de commande) et son plafond de validation. Sans périmètre, le rôle n'a aucun accès aux bons de commande. Les permissions Achats se donnent dans l'écran Rôles."
                />

                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-sm"
                >
                    <div
                        class="flex items-center justify-between border-b bg-muted/30 px-6 py-3"
                    >
                        <p class="text-sm font-medium">Règles par rôle</p>
                        <Button
                            size="sm"
                            :disabled="enregistrement"
                            @click="enregistrer"
                        >
                            <i
                                v-if="enregistrement"
                                class="pi pi-spin pi-spinner mr-2"
                            />
                            <Save v-else class="mr-2 h-4 w-4" />
                            Enregistrer
                        </Button>
                    </div>

                    <div class="divide-y">
                        <div
                            v-for="(l, index) in lignes"
                            :key="l.role_name"
                            class="grid gap-4 px-6 py-4 lg:grid-cols-[240px_1fr_240px]"
                        >
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    class="flex items-center gap-2 text-left text-sm font-medium"
                                    :aria-pressed="l.actif"
                                    @click="l.actif = !l.actif"
                                >
                                    <span
                                        class="flex h-4 w-4 shrink-0 items-center justify-center rounded border-2"
                                        :class="
                                            l.actif
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border'
                                        "
                                    >
                                        <Check v-if="l.actif" class="h-3 w-3" />
                                    </span>
                                    {{ l.role_label }}
                                </button>
                                <p
                                    v-if="!l.actif"
                                    class="pl-6 text-xs text-muted-foreground"
                                >
                                    Aucun accès aux bons de commande.
                                </p>
                                <p
                                    v-else
                                    class="pl-6 text-xs text-muted-foreground"
                                >
                                    {{ resumeValidation(l) }}
                                </p>
                                <p
                                    v-if="
                                        l.actif &&
                                        !l.a_permission &&
                                        (l.plafond_illimite ||
                                            l.plafond !== null)
                                    "
                                    class="flex items-start gap-1 pl-6 text-xs text-amber-600 dark:text-amber-400"
                                >
                                    <AlertTriangle
                                        class="mt-0.5 h-3 w-3 shrink-0"
                                    />
                                    Sans la permission « Achats — valider », ce
                                    plafond n'a pas d'effet.
                                </p>
                            </div>

                            <div v-if="l.actif" class="space-y-2">
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Peut acheter pour
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="p in PERIMETRES"
                                        :key="p.value"
                                        type="button"
                                        class="rounded-md border px-3 py-1 text-xs font-medium transition-colors"
                                        :class="
                                            l.perimetre === p.value
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border text-muted-foreground hover:text-foreground'
                                        "
                                        @click="l.perimetre = p.value"
                                    >
                                        {{ p.label }}
                                    </button>
                                </div>
                                <div
                                    v-if="
                                        l.perimetre === 'agences_selectionnees'
                                    "
                                    class="grid grid-cols-2 gap-1 rounded-lg border border-dashed p-2 sm:grid-cols-3"
                                >
                                    <button
                                        v-for="site in sites"
                                        :key="site.id"
                                        type="button"
                                        class="flex items-center gap-2 rounded px-2 py-1 text-left text-xs hover:bg-muted/50"
                                        @click="toggleSite(l, site.id)"
                                    >
                                        <span
                                            class="flex h-4 w-4 shrink-0 items-center justify-center rounded border-2"
                                            :class="
                                                l.sites.includes(site.id)
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border'
                                            "
                                        >
                                            <Check
                                                v-if="l.sites.includes(site.id)"
                                                class="h-2.5 w-2.5"
                                            />
                                        </span>
                                        <span class="truncate">{{
                                            site.nom
                                        }}</span>
                                    </button>
                                </div>
                                <p
                                    v-if="erreur(index, 'sites')"
                                    class="text-xs text-destructive"
                                >
                                    {{ erreur(index, 'sites') }}
                                </p>
                            </div>
                            <div
                                v-else
                                class="text-xs text-muted-foreground/60"
                            >
                                —
                            </div>

                            <div v-if="l.actif" class="space-y-2">
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Plafond de validation
                                </p>
                                <div class="relative">
                                    <input
                                        type="text"
                                        inputmode="numeric"
                                        :aria-label="`Plafond de validation — ${l.role_label}`"
                                        :value="affichage[l.role_name]"
                                        :disabled="l.plafond_illimite"
                                        placeholder="Ne valide pas"
                                        class="h-9 w-full rounded-md border bg-background py-1.5 pr-11 pl-2 text-right text-sm tabular-nums disabled:opacity-50"
                                        @input="onPlafondInput(l, $event)"
                                        @blur="onPlafondBlur(l)"
                                    />
                                    <span
                                        class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-muted-foreground"
                                        >GNF</span
                                    >
                                </div>
                                <button
                                    type="button"
                                    class="flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground"
                                    @click="
                                        l.plafond_illimite = !l.plafond_illimite
                                    "
                                >
                                    <span
                                        class="flex h-4 w-4 items-center justify-center rounded border-2"
                                        :class="
                                            l.plafond_illimite
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border'
                                        "
                                    >
                                        <Check
                                            v-if="l.plafond_illimite"
                                            class="h-3 w-3"
                                        />
                                    </span>
                                    Sans limite
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
