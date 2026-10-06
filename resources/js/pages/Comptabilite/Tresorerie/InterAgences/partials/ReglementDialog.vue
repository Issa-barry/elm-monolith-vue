<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatGNF } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Règlement inter-agences (ADR 0012) : l'agence débitrice choisit les encaissements qu'elle reverse
 * et le support d'où part l'argent. Aucun montant n'est saisi ni envoyé : le serveur le calcule à
 * partir des encaissements sélectionnés, crée le règlement et l'envoie en une seule opération.
 */
export interface LigneReglable {
    encaissement_id: string;
    facture_reference: string | null;
    client_nom: string | null;
    date_encaissement: string | null;
    montant: number;
    selectionnable: boolean;
}

export interface SupportReglement {
    id: string;
    libelle: string;
    type: string;
    solde: number;
}

const props = defineProps<{
    open: boolean;
    lignes: LigneReglable[];
    supports: SupportReglement[];
    debiteur: { id: string; nom: string };
    creancier: { id: string; nom: string };
}>();

const emit = defineEmits<{ (e: 'update:open', val: boolean): void }>();

const selection = ref<string[]>([]);
const supportId = ref('');
const commentaire = ref('');
const enCours = ref(false);
const erreurs = ref<Record<string, string>>({});

const reglables = computed(() => props.lignes.filter((l) => l.selectionnable));

// Croix de fermeture (dernier bouton enfant du contenu) : grisée pendant l'envoi.
const CROIX_INACTIVE =
    '[&>button:last-child]:pointer-events-none [&>button:last-child]:opacity-40';

watch(
    () => props.open,
    (ouvert) => {
        if (ouvert) {
            // Toutes les lignes « À verser » sont proposées cochées ; les autres ne le sont jamais.
            selection.value = reglables.value.map((l) => l.encaissement_id);
            supportId.value =
                props.supports.length === 1 ? props.supports[0].id : '';
            commentaire.value = '';
            erreurs.value = {};
        }
    },
    { immediate: true },
);

const total = computed(() =>
    reglables.value
        .filter((l) => selection.value.includes(l.encaissement_id))
        .reduce((somme, l) => somme + l.montant, 0),
);

const supportChoisi = computed(
    () => props.supports.find((s) => s.id === supportId.value) ?? null,
);

const soldeInsuffisant = computed(
    () =>
        supportChoisi.value !== null &&
        total.value > supportChoisi.value.solde + 0.004,
);

const toutCoche = computed(
    () =>
        reglables.value.length > 0 &&
        selection.value.length === reglables.value.length,
);

function basculer(id: string, coche: boolean | 'indeterminate'): void {
    selection.value = coche
        ? [...new Set([...selection.value, id])]
        : selection.value.filter((s) => s !== id);
}

function basculerTout(coche: boolean | 'indeterminate'): void {
    selection.value = coche
        ? reglables.value.map((l) => l.encaissement_id)
        : [];
}

const peutEnvoyer = computed(
    () =>
        !enCours.value &&
        selection.value.length > 0 &&
        supportChoisi.value !== null &&
        !soldeInsuffisant.value,
);

const erreurGenerale = computed(() => {
    const cles = Object.keys(erreurs.value).filter(
        (c) => c !== 'compte_tresorerie_origine_id',
    );
    return cles.length ? erreurs.value[cles[0]] : null;
});

function fermer(ouvert: boolean): void {
    if (!enCours.value) emit('update:open', ouvert);
}

function regler(): void {
    if (!peutEnvoyer.value) return;
    enCours.value = true;
    erreurs.value = {};

    router.post(
        `/backoffice/comptabilite/tresorerie/inter-agences/${props.debiteur.id}/${props.creancier.id}/reglements`,
        {
            encaissement_ids: selection.value,
            compte_tresorerie_origine_id: supportId.value,
            commentaire: commentaire.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onError: (e) => {
                erreurs.value = e as Record<string, string>;
            },
            onFinish: () => {
                enCours.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="fermer">
        <DialogContent
            class="sm:max-w-2xl"
            :class="{ [CROIX_INACTIVE]: enCours }"
        >
            <DialogHeader>
                <DialogTitle>
                    Régler {{ debiteur.nom }} → {{ creancier.nom }}
                </DialogTitle>
            </DialogHeader>

            <div class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Les encaissements cochés sont reversés à
                    {{ creancier.nom }}. Le règlement est envoyé immédiatement ;
                    {{ creancier.nom }} confirmera la réception depuis
                    Mouvements.
                </p>

                <div class="max-h-72 overflow-y-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="w-10 px-3 py-2">
                                    <Checkbox
                                        :model-value="toutCoche"
                                        :disabled="reglables.length === 0"
                                        aria-label="Tout sélectionner"
                                        @update:model-value="basculerTout"
                                    />
                                </th>
                                <th class="px-3 py-2 font-medium">Commande</th>
                                <th class="px-3 py-2 font-medium">Client</th>
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Montant
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="ligne in reglables"
                                :key="ligne.encaissement_id"
                                data-testid="ligne-reglable"
                            >
                                <td class="px-3 py-2">
                                    <Checkbox
                                        :model-value="
                                            selection.includes(
                                                ligne.encaissement_id,
                                            )
                                        "
                                        :aria-label="`Régler ${ligne.facture_reference}`"
                                        @update:model-value="
                                            (v: boolean | 'indeterminate') =>
                                                basculer(
                                                    ligne.encaissement_id,
                                                    v,
                                                )
                                        "
                                    />
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ ligne.facture_reference ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ ligne.client_nom ?? '—' }}
                                </td>
                                <td class="px-3 py-2 tabular-nums">
                                    {{ ligne.date_encaissement ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(ligne.montant) }}
                                </td>
                            </tr>
                            <tr v-if="reglables.length === 0">
                                <td
                                    colspan="5"
                                    class="px-3 py-6 text-center text-muted-foreground"
                                >
                                    Aucun encaissement à verser.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Montant : toujours calculé, jamais saisissable. -->
                <div
                    class="flex items-center justify-between rounded-xl border border-primary/20 bg-primary/10 px-4 py-3"
                >
                    <span class="text-sm font-semibold text-primary"
                        >Montant du règlement</span
                    >
                    <span
                        class="text-xl font-extrabold text-primary tabular-nums"
                        data-testid="reglement-total"
                        >{{ formatGNF(total) }}</span
                    >
                </div>

                <div class="space-y-1.5">
                    <Label for="support-reglement"
                        >Support d'où part l'argent ({{ debiteur.nom }})</Label
                    >
                    <select
                        id="support-reglement"
                        v-model="supportId"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        data-testid="reglement-support"
                    >
                        <option value="" disabled>Sélectionner…</option>
                        <option v-for="s in supports" :key="s.id" :value="s.id">
                            {{ s.libelle }} — {{ formatGNF(s.solde) }}
                            disponible
                        </option>
                    </select>
                    <!-- Information : les espèces des caisses d'agents doivent d'abord être versées à l'agence. -->
                    <p
                        v-if="supports.length === 0"
                        class="text-xs text-blue-700 dark:text-blue-400"
                        data-testid="reglement-aucun-support"
                    >
                        Aucun support actif et validé dans {{ debiteur.nom }}.
                        Les caisses d'agents ne peuvent pas régler : versez
                        d'abord leurs espèces à la caisse de l'agence.
                    </p>
                    <p
                        v-if="soldeInsuffisant"
                        class="text-xs text-destructive"
                        data-testid="reglement-solde-insuffisant"
                    >
                        Solde insuffisant :
                        {{ formatGNF(supportChoisi?.solde ?? 0) }} disponible.
                    </p>
                    <p
                        v-if="erreurs.compte_tresorerie_origine_id"
                        class="text-xs text-destructive"
                    >
                        {{ erreurs.compte_tresorerie_origine_id }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label for="commentaire-reglement"
                        >Commentaire (facultatif)</Label
                    >
                    <input
                        id="commentaire-reglement"
                        v-model="commentaire"
                        maxlength="500"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                    />
                </div>

                <p
                    v-if="erreurGenerale"
                    class="text-sm text-destructive"
                    data-testid="reglement-erreur"
                >
                    {{ erreurGenerale }}
                </p>
            </div>

            <DialogFooter>
                <button
                    type="button"
                    :disabled="enCours"
                    class="h-9 rounded-md border px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    @click="fermer(false)"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    data-testid="reglement-envoyer"
                    :disabled="!peutEnvoyer"
                    :aria-busy="enCours"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                    @click="regler"
                >
                    <Spinner v-if="enCours" />
                    {{ enCours ? 'Envoi…' : 'Envoyer le règlement' }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
