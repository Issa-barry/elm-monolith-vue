<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { onClickOutside } from '@vueuse/core';
import { Check, Info, Loader2, Search } from 'lucide-vue-next';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Textarea from 'primevue/textarea';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Commande de référence d'une dépense de carburant (ADR 0031) : champ de recherche (comme la
 * recherche globale) sur les commandes rattachables du véhicule, du jour et de l'agence de la
 * dépense — par référence, code abrégé `JJ-NNN` ou client —, ou exception motivée. Le serveur
 * revérifie toujours le rattachement à l'enregistrement.
 */
export interface CommandeCandidate {
    type: 'vente' | 'transfert';
    id: string;
    reference: string;
    nature_label: string;
    code_abrege?: string | null;
    date?: string;
    statut?: string | null;
    statut_label?: string | null;
    detail?: string;
    depenses_liees?: number;
}

const props = defineProps<{
    vehiculeId: string;
    dateDepense: string;
    /** Agence de la dépense ; vide = toutes les agences. */
    siteId: string;
    error?: string;
    motifError?: string;
}>();

const selection = defineModel<CommandeCandidate | null>('selection', {
    required: true,
});
const sansCommande = defineModel<boolean>('sansCommande', { required: true });
const motif = defineModel<string>('motif', { required: true });

const candidats = ref<CommandeCandidate[]>([]);
const message = ref<string | null>(null);
const chargement = ref(false);
const recherche = ref('');
const ouvert = ref(false);
const actif = ref(0);
const conteneur = ref<HTMLElement | null>(null);
let requete = 0;

const cle = (c: CommandeCandidate) => `${c.type}:${c.id}`;

const normaliser = (texte: string) =>
    texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const resultats = computed(() => {
    const termes = normaliser(recherche.value).split(/\s+/).filter(Boolean);
    if (termes.length === 0) return candidats.value;

    return candidats.value.filter((c) => {
        const texte = normaliser(
            [c.reference, c.code_abrege, c.detail, c.nature_label]
                .filter(Boolean)
                .join(' '),
        );
        return termes.every((t) => texte.includes(t));
    });
});

watch(resultats, () => {
    actif.value = 0;
});

onClickOutside(conteneur, () => {
    ouvert.value = false;
});

async function charger(conserverSelection: boolean) {
    candidats.value = [];
    message.value = null;
    if (!props.vehiculeId || !props.dateDepense) return;

    const params = new URLSearchParams({
        vehicule_id: props.vehiculeId,
        date_depense: props.dateDepense,
    });
    if (props.siteId) params.set('site_id', props.siteId);

    const numero = ++requete;
    chargement.value = true;
    try {
        const res = await fetch(
            `/backoffice/depenses/commandes-carburant?${params.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        if (numero !== requete) return;
        if (!res.ok) {
            message.value = 'Le chargement des commandes a échoué.';
            return;
        }
        const data = (await res.json()) as {
            candidats: CommandeCandidate[];
            message: string | null;
        };
        candidats.value = data.candidats;
        message.value =
            data.candidats.length === 0
                ? 'Aucune commande ne correspond à cette sélection.'
                : data.message;
        // Véhicule, date ou agence modifiés : une commande qui n'est plus rattachable est retirée.
        if (
            !conserverSelection &&
            selection.value &&
            !data.candidats.some((c) => cle(c) === cle(selection.value!))
        ) {
            selection.value = null;
        }
    } catch {
        if (numero === requete) {
            message.value = 'Le chargement des commandes a échoué.';
        }
    } finally {
        if (numero === requete) chargement.value = false;
    }
}

watch(
    () => [props.vehiculeId, props.dateDepense, props.siteId] as const,
    (_nouveau, ancien) => charger(ancien === undefined),
    { immediate: true },
);

watch(sansCommande, (actifSansCommande) => {
    if (actifSansCommande) selection.value = null;
});

function choisir(c: CommandeCandidate) {
    selection.value = c;
    recherche.value = '';
    ouvert.value = false;
}

async function changer() {
    selection.value = null;
    ouvert.value = true;
    await nextTick();
    document.getElementById('dep-commande')?.focus();
}

function deplacer(pas: number) {
    ouvert.value = true;
    const n = resultats.value.length;
    if (n > 0) actif.value = (actif.value + pas + n) % n;
}

function valider() {
    const c = resultats.value[actif.value];
    if (ouvert.value && c) choisir(c);
}

function formatDate(iso?: string) {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}
</script>

<template>
    <div class="min-w-0 space-y-3" data-testid="commande-carburant-field">
        <div>
            <Label for="dep-commande" class="block text-sm font-medium">
                Commande liée
                <span v-if="!sansCommande" class="text-destructive">*</span>
            </Label>
            <p
                id="dep-commande-aide"
                class="mt-1 text-xs text-muted-foreground"
            >
                Ventes et transferts du véhicule à la date choisie<template
                    v-if="siteId"
                    >, dans cette agence</template
                >.
            </p>
        </div>

        <p v-if="!vehiculeId" class="text-xs text-muted-foreground">
            Sélectionnez d'abord le véhicule.
        </p>

        <template v-else-if="!sansCommande">
            <div
                v-if="selection"
                class="flex items-start justify-between gap-3 rounded-lg border p-3"
                data-testid="commande-carburant-selection"
            >
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 text-sm font-semibold">
                        <Check class="h-4 w-4 shrink-0 text-primary" />
                        <span class="font-mono break-all">{{
                            selection.reference
                        }}</span>
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ selection.nature_label }}
                        <template v-if="selection.date">
                            · {{ formatDate(selection.date) }}</template
                        >
                        <template v-if="selection.detail">
                            · {{ selection.detail }}</template
                        >
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-11 shrink-0 sm:min-h-0"
                    @click="changer"
                >
                    Changer
                </Button>
            </div>

            <div v-else ref="conteneur" class="relative">
                <IconField class="w-full">
                    <InputIcon class="pointer-events-none">
                        <Loader2
                            v-if="chargement"
                            class="h-4 w-4 animate-spin text-muted-foreground"
                        />
                        <Search v-else class="h-4 w-4 text-muted-foreground" />
                    </InputIcon>
                    <InputText
                        id="dep-commande"
                        v-model="recherche"
                        placeholder="Code, référence ou client…"
                        autocomplete="off"
                        class="w-full min-w-0 max-sm:min-h-11 max-sm:text-base"
                        :invalid="!!error"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-describedby="dep-commande-aide"
                        aria-controls="dep-commande-resultats"
                        :aria-expanded="ouvert"
                        @focus="ouvert = true"
                        @input="ouvert = true"
                        @keydown.down.prevent="deplacer(1)"
                        @keydown.up.prevent="deplacer(-1)"
                        @keydown.enter.prevent="valider"
                        @keydown.escape="ouvert = false"
                    />
                </IconField>

                <div
                    v-if="ouvert"
                    id="dep-commande-resultats"
                    class="absolute top-full right-0 left-0 z-50 mt-1 max-h-[min(20rem,50dvh)] overflow-y-auto rounded-lg border bg-background shadow-lg"
                    role="listbox"
                    data-testid="commande-carburant-resultats"
                >
                    <div
                        v-if="chargement"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Chargement des commandes…
                    </div>
                    <div
                        v-else-if="candidats.length === 0"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        {{ message ?? 'Aucune commande.' }}
                    </div>
                    <div
                        v-else-if="resultats.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Aucun résultat pour
                        <span class="font-medium">« {{ recherche }} »</span>
                    </div>
                    <template v-else>
                        <button
                            v-for="(c, i) in resultats"
                            :key="cle(c)"
                            type="button"
                            role="option"
                            :aria-selected="i === actif"
                            :data-cle="cle(c)"
                            class="flex min-h-11 w-full flex-col gap-0.5 px-3 py-2 text-left transition-colors hover:bg-muted/50"
                            :class="{ 'bg-muted/50': i === actif }"
                            @mouseenter="actif = i"
                            @click="choisir(c)"
                        >
                            <span
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="font-mono text-sm font-semibold">{{
                                    c.reference
                                }}</span>
                                <StatusDot
                                    v-if="c.statut_label"
                                    :status="c.statut"
                                    :label="c.statut_label"
                                />
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ c.nature_label }} · {{ formatDate(c.date) }}
                                <template v-if="c.detail">
                                    · {{ c.detail }}</template
                                >
                            </span>
                            <span
                                v-if="c.depenses_liees"
                                class="text-xs text-amber-700 dark:text-amber-400"
                            >
                                Déjà rattachée à
                                {{ c.depenses_liees }} dépense{{
                                    c.depenses_liees > 1 ? 's' : ''
                                }}.
                            </span>
                        </button>
                    </template>
                </div>
            </div>

            <Message
                v-if="message && !chargement && !ouvert"
                severity="secondary"
                variant="simple"
                size="small"
                data-testid="commande-carburant-message"
            >
                <template #icon><Info class="size-4 shrink-0" /></template>
                {{ message }}
            </Message>
        </template>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>

        <div v-if="vehiculeId" class="space-y-3">
            <div class="flex items-center gap-2">
                <Checkbox
                    id="dep-sans-commande"
                    :model-value="sansCommande"
                    @update:model-value="sansCommande = $event === true"
                />
                <Label
                    for="dep-sans-commande"
                    class="flex min-h-11 cursor-pointer items-center text-sm font-normal sm:min-h-0"
                >
                    Dépense sans commande
                </Label>
            </div>
            <template v-if="sansCommande">
                <div class="space-y-2">
                    <Label
                        for="dep-motif-sans-commande"
                        class="text-sm font-medium"
                    >
                        Motif <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        id="dep-motif-sans-commande"
                        v-model="motif"
                        rows="2"
                        placeholder="Ex. : trajet au garage"
                        class="w-full min-w-0 resize-y max-sm:text-base"
                        :invalid="!!motifError"
                        aria-describedby="dep-motif-aide"
                    />
                    <p
                        id="dep-motif-aide"
                        class="text-xs text-muted-foreground"
                    >
                        Ce motif sera transmis au valideur.
                    </p>
                    <p v-if="motifError" class="text-xs text-destructive">
                        {{ motifError }}
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>
