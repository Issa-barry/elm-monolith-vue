<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref, watch } from 'vue';

const GUINEA_PREFIX = '+224';
const ROLES = [
    { value: 'chauffeur', label: 'Chauffeur' },
    { value: 'convoyeur', label: 'Convoyeur' },
];

interface ProprietaireOption {
    value: string;
    label: string;
    telephone?: string;
}

interface VehiculeInfo {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    categorie: string | null;
    capacite_packs: number | null;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
}

interface MembreExistant {
    livreur_id: string | null;
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    montant_par_pack: number;
    ordre: number;
}

interface EquipeExistante {
    id: string;
    is_active: boolean;
    commission_unitaire_par_pack: number;
    montant_par_pack_proprietaire: number | null;
    taux_commission_proprietaire: number | null;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    membres: MembreExistant[];
}

interface MembreLigne {
    livreur_id: string | null;
    role: string;
    prenom: string;
    nom: string;
    telephone: string; // 9 chiffres locaux
    montant_par_pack: number;
    ordre: number;
    _errors: Partial<Record<'role' | 'prenom' | 'nom' | 'telephone', string>>;
}

interface LignePartage {
    id: string;
    label: string;
    montant: number;
    taux: number;
}

const props = defineProps<{
    visible: boolean;
    vehicule: VehiculeInfo;
    equipe: EquipeExistante | null;
    proprietaires: ProprietaireOption[];
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

// ── State ───────────────────────────────────────────────────────────────────

const step = ref(1);
const isSubmitting = ref(false);
const serverErrors = reactive<Record<string, string>>({});
const showConfirmClose = ref(false);
const hasChanges = ref(false);

function markChanged() {
    hasChanges.value = true;
}

function requestClose() {
    if (hasChanges.value) {
        showConfirmClose.value = true;
    } else {
        emit('update:visible', false);
    }
}

function confirmClose() {
    showConfirmClose.value = false;
    hasChanges.value = false;
    emit('update:visible', false);
}

const membres = ref<MembreLigne[]>([]);
const commission = ref(0);
const montantProp = ref(0);
const lignes = ref<LignePartage[]>([]);

// ── Computed ────────────────────────────────────────────────────────────────

const isExterne = computed(() => props.vehicule.categorie === 'externe');

const proprietaireNom = computed(() => {
    if (!isExterne.value) return null;
    const p = props.proprietaires.find(
        (p) => p.value === props.vehicule.proprietaire_id,
    );
    return p?.label ?? props.vehicule.proprietaire_nom ?? null;
});

const stepTitle = computed(() =>
    props.equipe ? "Modifier l'équipe" : "Configurer l'équipe",
);

// ── Init ────────────────────────────────────────────────────────────────────

watch(
    () => props.visible,
    (val) => {
        if (!val) return;
        step.value = 1;
        hasChanges.value = false;
        showConfirmClose.value = false;
        Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);
        isSubmitting.value = false;

        if (props.equipe) {
            membres.value = props.equipe.membres.map((m) => ({
                livreur_id: m.livreur_id,
                role: m.role,
                prenom: m.prenom,
                nom: m.nom,
                telephone: m.telephone.startsWith(GUINEA_PREFIX)
                    ? m.telephone.slice(GUINEA_PREFIX.length)
                    : m.telephone.replace(/\D/g, '').slice(-9),
                montant_par_pack: m.montant_par_pack,
                ordre: m.ordre,
                _errors: {},
            }));
            commission.value = props.equipe.commission_unitaire_par_pack;
            montantProp.value = props.equipe.montant_par_pack_proprietaire ?? 0;
        } else {
            membres.value = [
                {
                    livreur_id: null,
                    role: '',
                    prenom: '',
                    nom: '',
                    telephone: '',
                    montant_par_pack: 0,
                    ordre: 0,
                    _errors: {},
                },
            ];
            commission.value = 950;
            montantProp.value = 0;
        }
    },
);

// ── Étape 1 : Membres ───────────────────────────────────────────────────────

function addLigne() {
    markChanged();
    membres.value.push({
        livreur_id: null,
        role: '',
        prenom: '',
        nom: '',
        telephone: '',
        montant_par_pack: 0,
        ordre: membres.value.length,
        _errors: {},
    });
}

function removeLigne(idx: number) {
    markChanged();
    membres.value.splice(idx, 1);
    membres.value.forEach((m, i) => (m.ordre = i));
}

function handlePhoneKeydown(e: KeyboardEvent) {
    const pass = [
        'Backspace',
        'Delete',
        'Tab',
        'Escape',
        'Enter',
        'ArrowLeft',
        'ArrowRight',
        'Home',
        'End',
    ];
    if (pass.includes(e.key)) return;
    if (
        (e.ctrlKey || e.metaKey) &&
        ['a', 'c', 'v', 'x'].includes(e.key.toLowerCase())
    )
        return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

function onPhoneInput(e: Event, idx: number) {
    markChanged();
    const raw = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    const local = raw.slice(0, 9);
    membres.value[idx].telephone = local;
    (e.target as HTMLInputElement).value = local;
}

function validateStep1(): boolean {
    const phones = new Set<string>();
    let valid = true;

    membres.value.forEach((m) => {
        m._errors = {};
        if (!m.role) {
            m._errors.role = 'Rôle requis';
            valid = false;
        }
        if (!m.prenom.trim()) {
            m._errors.prenom = 'Prénom requis';
            valid = false;
        }
        if (!m.nom.trim()) {
            m._errors.nom = 'Nom requis';
            valid = false;
        }
        if (!m.telephone || !/^\d{9}$/.test(m.telephone)) {
            m._errors.telephone = '9 chiffres requis';
            valid = false;
        } else if (phones.has(m.telephone)) {
            m._errors.telephone = 'Numéro déjà utilisé';
            valid = false;
        } else {
            phones.add(m.telephone);
        }
    });

    return valid && membres.value.length > 0;
}

function goToStep2() {
    if (!validateStep1()) return;
    markChanged();
    buildLignes();
    if (commission.value <= 0) commission.value = 950;
    step.value = 2;
}

// ── Étape 2 : Partage ───────────────────────────────────────────────────────

function toTaux(montant: number, comm: number): number {
    if (!comm || comm <= 0) return 0;
    return parseFloat(((montant / comm) * 100).toFixed(2));
}

function toMontant(taux: number, comm: number): number {
    return Math.round((taux / 100) * comm);
}

function buildLignes() {
    const comm = commission.value > 0 ? commission.value : 950;
    const newLignes: LignePartage[] = [];

    if (isExterne.value) {
        newLignes.push({
            id: 'proprietaire',
            label: `Propriétaire — ${proprietaireNom.value ?? '—'}`,
            montant: montantProp.value,
            taux: toTaux(montantProp.value, comm),
        });
    }

    const roleCounts: Record<string, number> = {};
    membres.value.forEach((m, i) => {
        roleCounts[m.role] = (roleCounts[m.role] ?? 0) + 1;
        const rl =
            m.role === 'chauffeur'
                ? `Chauffeur ${roleCounts[m.role]}`
                : `Convoyeur ${roleCounts[m.role]}`;
        newLignes.push({
            id: `membre-${i}`,
            label: `${rl} — ${m.prenom} ${m.nom}`,
            montant: m.montant_par_pack,
            taux: toTaux(m.montant_par_pack, comm),
        });
    });

    lignes.value = newLignes;
}

watch(commission, (newComm) => {
    lignes.value.forEach((l) => {
        l.taux = toTaux(l.montant, newComm);
    });
});

function onMontantChange(ligne: LignePartage, val: number | null) {
    markChanged();
    ligne.montant = val ?? 0;
    ligne.taux = toTaux(ligne.montant, commission.value);
}

function onTauxChange(ligne: LignePartage, val: number | null) {
    markChanged();
    ligne.taux = val ?? 0;
    ligne.montant = toMontant(ligne.taux, commission.value);
}

const totalPartage = computed(() =>
    lignes.value.reduce((s, l) => s + (l.montant || 0), 0),
);

const partageValide = computed(
    () =>
        commission.value > 0 &&
        Math.abs(totalPartage.value - commission.value) < 0.01,
);

function applyPartageToMembres() {
    membres.value = membres.value.map((m, i) => {
        const ligne = lignes.value.find((l) => l.id === `membre-${i}`);
        return { ...m, montant_par_pack: ligne?.montant ?? m.montant_par_pack };
    });
    if (isExterne.value) {
        const propLigne = lignes.value.find((l) => l.id === 'proprietaire');
        montantProp.value = propLigne?.montant ?? 0;
    }
}

function goToStep3() {
    if (!partageValide.value) return;
    applyPartageToMembres();
    step.value = 3;
}

// ── Étape 3 : Récapitulatif ─────────────────────────────────────────────────

function roleLabel(role: string, index: number): string {
    const count = membres.value
        .slice(0, index + 1)
        .filter((m) => m.role === role).length;
    return role === 'chauffeur' ? `Chauffeur ${count}` : `Convoyeur ${count}`;
}

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatPhone(local: string): string {
    const d = local.replace(/\D/g, '');
    return `+224 ${d.slice(0, 3)} ${d.slice(3, 5)} ${d.slice(5, 7)} ${d.slice(7)}`;
}

// ── Soumission ──────────────────────────────────────────────────────────────

function buildPayload() {
    return {
        vehicule_id: props.vehicule.id,
        proprietaire_id: isExterne.value
            ? props.vehicule.proprietaire_id
            : null,
        is_active: props.equipe?.is_active ?? true,
        commission_unitaire_par_pack: commission.value,
        montant_par_pack_proprietaire: isExterne.value
            ? montantProp.value
            : null,
        membres: membres.value.map((m, i) => ({
            livreur_id: m.livreur_id ?? null,
            nom: m.nom,
            prenom: m.prenom,
            telephone: `${GUINEA_PREFIX}${m.telephone}`,
            role: m.role,
            montant_par_pack: m.montant_par_pack,
            ordre: i,
        })),
    };
}

function submit() {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);

    const payload = buildPayload();
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
            isSubmitting.value = false;
        },
        onError: (errors: Record<string, string>) => {
            isSubmitting.value = false;
            Object.assign(serverErrors, errors);
            step.value = 1;
        },
    };

    if (props.equipe) {
        router.patch(`/equipes-livraison/${props.equipe.id}`, payload, options);
    } else {
        router.post('/equipes-livraison', payload, options);
    }
}

const hasStep1Errors = computed(() =>
    membres.value.some((m) => Object.keys(m._errors).length > 0),
);
</script>

<template>
    <!-- ── Confirmation fermeture ────────────────────────────────────────── -->
    <Dialog
        v-model:visible="showConfirmClose"
        modal
        header="Quitter sans enregistrer ?"
        :style="{ width: 'min(400px, 90vw)' }"
        append-to="body"
        :closable="false"
    >
        <p class="text-sm text-muted-foreground">
            Vos modifications seront perdues.
        </p>
        <template #footer>
            <div class="flex w-full items-center justify-between">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="showConfirmClose = false"
                >
                    Continuer l'édition
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    @click="confirmClose"
                >
                    Quitter
                </Button>
            </div>
        </template>
    </Dialog>

    <!-- ── Modal principal ───────────────────────────────────────────────── -->
    <Dialog
        :visible="visible"
        modal
        :header="stepTitle"
        :style="{ width: 'min(960px, 95vw)' }"
        :dismissable-mask="false"
        :closable="true"
        @update:visible="
            (val) => {
                if (!val) requestClose();
            }
        "
    >
        <!-- ── Indicateur d'étapes ────────────────────────────────────────── -->
        <div class="mb-6 flex items-center gap-2">
            <template v-for="n in 3" :key="n">
                <div class="flex shrink-0 items-center gap-2">
                    <div
                        class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                        :class="
                            step > n
                                ? 'bg-emerald-500 text-white'
                                : step === n
                                  ? 'bg-primary text-primary-foreground'
                                  : 'bg-muted text-muted-foreground'
                        "
                    >
                        <Check v-if="step > n" class="h-3.5 w-3.5" />
                        <span v-else>{{ n }}</span>
                    </div>
                    <span
                        class="hidden text-sm sm:inline"
                        :class="
                            step === n
                                ? 'font-medium text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            n === 1
                                ? 'Membres'
                                : n === 2
                                  ? 'Partage'
                                  : 'Récapitulatif'
                        }}
                    </span>
                </div>
                <div v-if="n < 3" class="h-px flex-1 bg-border" />
            </template>
        </div>

        <!-- Erreurs serveur -->
        <div
            v-if="Object.keys(serverErrors).length > 0"
            class="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <p v-for="(msg, key) in serverErrors" :key="key">{{ msg }}</p>
        </div>

        <!-- ── Étape 1 : Membres ─────────────────────────────────────────── -->
        <div v-if="step === 1" class="space-y-4">
            <p v-if="membres.length > 0" class="text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{
                    membres.length
                }}</span>
                membre{{ membres.length !== 1 ? 's' : '' }}
            </p>

            <div
                v-if="membres.length === 0"
                class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                Aucun membre. Cliquez sur « + Ajouter un membre » ci-dessous
                pour commencer.
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[680px] text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground"
                        >
                            <th class="w-36 px-3 py-2.5">Rôle *</th>
                            <th class="px-3 py-2.5">Prénom *</th>
                            <th class="px-3 py-2.5">Nom *</th>
                            <th class="w-52 px-3 py-2.5">Téléphone *</th>
                            <th class="w-10 px-3 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(m, i) in membres"
                            :key="i"
                            class="align-top"
                        >
                            <!-- Rôle -->
                            <td class="px-3 py-2">
                                <Dropdown
                                    v-model="m.role"
                                    :options="ROLES"
                                    option-label="label"
                                    option-value="value"
                                    placeholder="Rôle…"
                                    class="w-full"
                                    :class="{ 'p-invalid': m._errors.role }"
                                    append-to="body"
                                    :data-testid="`role-dropdown-${i}`"
                                    @change="markChanged"
                                />
                                <p
                                    v-if="m._errors.role"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ m._errors.role }}
                                </p>
                            </td>

                            <!-- Prénom -->
                            <td class="px-3 py-2">
                                <InputText
                                    v-model="m.prenom"
                                    class="w-full"
                                    :class="{ 'p-invalid': m._errors.prenom }"
                                    placeholder="Prénom"
                                    :data-testid="`prenom-${i}`"
                                    @input="markChanged"
                                />
                                <p
                                    v-if="m._errors.prenom"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ m._errors.prenom }}
                                </p>
                            </td>

                            <!-- Nom -->
                            <td class="px-3 py-2">
                                <InputText
                                    v-model="m.nom"
                                    class="w-full"
                                    :class="{ 'p-invalid': m._errors.nom }"
                                    placeholder="Nom"
                                    :data-testid="`nom-${i}`"
                                    @input="markChanged"
                                />
                                <p
                                    v-if="m._errors.nom"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ m._errors.nom }}
                                </p>
                            </td>

                            <!-- Téléphone -->
                            <td class="px-3 py-2">
                                <div
                                    class="flex h-9 overflow-hidden rounded-md border"
                                    :class="
                                        m._errors.telephone
                                            ? 'border-destructive'
                                            : 'border-input'
                                    "
                                >
                                    <span
                                        class="flex shrink-0 items-center gap-1 border-r bg-muted px-2 text-xs text-muted-foreground select-none"
                                    >
                                        <img
                                            src="https://flagcdn.com/16x12/gn.png"
                                            width="16"
                                            height="12"
                                            alt="GN"
                                        />
                                        +224
                                    </span>
                                    <input
                                        type="tel"
                                        inputmode="numeric"
                                        maxlength="9"
                                        :value="m.telephone"
                                        placeholder="9 chiffres"
                                        class="min-w-0 flex-1 bg-background px-2 text-sm outline-none placeholder:text-muted-foreground"
                                        :data-testid="`telephone-${i}`"
                                        @input="onPhoneInput($event, i)"
                                        @keydown="handlePhoneKeydown"
                                    />
                                </div>
                                <p
                                    v-if="m._errors.telephone"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ m._errors.telephone }}
                                </p>
                            </td>

                            <!-- Actions -->
                            <td class="px-3 py-2">
                                <button
                                    type="button"
                                    class="mt-0.5 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    @click="removeLigne(i)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="hasStep1Errors" class="text-xs text-destructive">
                Corrigez les erreurs dans le tableau avant de continuer.
            </p>
        </div>

        <!-- ── Étape 2 : Partage ─────────────────────────────────────────── -->
        <div v-else-if="step === 2" class="space-y-5">
            <div>
                <Label
                    for="step-commission"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Commission unitaire par pack (GNF)
                    <span class="text-destructive">*</span>
                </Label>
                <InputNumber
                    v-model="commission"
                    input-id="step-commission"
                    :min="1"
                    :max-fraction-digits="0"
                    suffix=" GNF"
                    class="w-full"
                    :input-style="{ textAlign: 'right', width: '100%' }"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Montant total à répartir entre tous les bénéficiaires.
                </p>
            </div>

            <div
                v-if="lignes.length > 0"
                class="overflow-hidden rounded-lg border"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-3 py-2.5 text-left text-xs font-medium text-muted-foreground"
                            >
                                Bénéficiaire
                            </th>
                            <th
                                class="px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                            >
                                Montant (GNF)
                            </th>
                            <th
                                class="w-32 px-3 py-2.5 text-right text-xs font-medium text-muted-foreground"
                            >
                                %
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ligne in lignes"
                            :key="ligne.id"
                            class="border-b last:border-b-0"
                            :class="
                                ligne.id === 'proprietaire'
                                    ? 'bg-primary/5'
                                    : ''
                            "
                        >
                            <td class="px-3 py-2 text-sm">
                                <template v-if="ligne.id === 'proprietaire'">
                                    <span
                                        class="mr-1.5 inline-flex items-center rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary-foreground uppercase"
                                        >Propriétaire</span
                                    >
                                    <span class="font-medium text-primary">{{
                                        ligne.label.replace(
                                            'Propriétaire — ',
                                            '',
                                        )
                                    }}</span>
                                </template>
                                <template v-else>{{ ligne.label }}</template>
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    :model-value="ligne.montant"
                                    :min="0"
                                    :max="commission"
                                    :max-fraction-digits="0"
                                    class="w-full"
                                    :input-style="{
                                        textAlign: 'right',
                                        width: '100%',
                                    }"
                                    @update:model-value="
                                        onMontantChange(ligne, $event)
                                    "
                                />
                            </td>
                            <td class="px-3 py-2">
                                <InputNumber
                                    :model-value="ligne.taux"
                                    :min="0"
                                    :max="100"
                                    :max-fraction-digits="2"
                                    suffix=" %"
                                    :disabled="!commission || commission <= 0"
                                    class="w-full"
                                    :input-style="{
                                        textAlign: 'right',
                                        width: '100%',
                                    }"
                                    @update:model-value="
                                        onTauxChange(ligne, $event)
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t bg-muted/20">
                            <td class="px-3 py-2.5 text-sm font-semibold">
                                Total
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-mono text-sm font-semibold"
                                :class="
                                    partageValide
                                        ? 'text-emerald-600'
                                        : 'text-destructive'
                                "
                            >
                                {{ totalPartage }} GNF
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-mono text-xs"
                            >
                                <span
                                    v-if="partageValide"
                                    class="font-semibold text-emerald-600"
                                    >✓ 100 %</span
                                >
                                <span v-else class="text-destructive"
                                    >≠ {{ commission }} GNF</span
                                >
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p
                v-if="!partageValide && lignes.length > 0 && commission > 0"
                class="text-xs text-destructive"
            >
                La somme ({{ totalPartage }} GNF) doit être égale à la
                commission ({{ commission }} GNF). Différence :
                {{ Math.abs(totalPartage - commission) }} GNF.
            </p>
        </div>

        <!-- ── Étape 3 : Récapitulatif ───────────────────────────────────── -->
        <div v-else-if="step === 3" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Véhicule
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ vehicule.nom_vehicule }}
                    </p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                    <p
                        v-if="vehicule.capacite_packs"
                        class="text-xs text-muted-foreground"
                    >
                        {{ vehicule.capacite_packs }} packs
                    </p>
                </div>

                <div
                    v-if="isExterne && proprietaireNom"
                    class="rounded-lg border bg-muted/30 p-3"
                >
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Propriétaire
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ proprietaireNom }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Part : {{ formatGNF(montantProp) }}
                    </p>
                </div>

                <div class="rounded-lg border bg-muted/30 p-3">
                    <p
                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Commission / pack
                    </p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ formatGNF(commission) }}
                    </p>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border">
                <div class="border-b bg-muted/30 px-4 py-2.5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Membres ({{ membres.length }})
                    </p>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th class="px-4 py-2 font-medium">Membre</th>
                            <th class="px-4 py-2 font-medium">Téléphone</th>
                            <th class="px-4 py-2 font-medium">Rôle</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Part
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(m, i) in membres" :key="i">
                            <td class="px-4 py-2.5 font-medium">
                                {{ m.prenom }} {{ m.nom }}
                            </td>
                            <td
                                class="px-4 py-2.5 font-mono text-xs text-muted-foreground"
                            >
                                {{ formatPhone(m.telephone) }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-muted-foreground capitalize"
                            >
                                {{ roleLabel(m.role, i) }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs"
                            >
                                {{ formatGNF(m.montant_par_pack) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Footer navigation ─────────────────────────────────────────── -->
        <template #footer>
            <div class="flex w-full items-center justify-between">
                <!-- Bouton gauche -->
                <Button
                    v-if="step > 1"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="step--"
                >
                    <ChevronLeft class="mr-1 h-4 w-4" />
                    Retour
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addLigne"
                >
                    <Plus class="mr-1.5 h-4 w-4" />
                    Ajouter un membre
                </Button>

                <!-- Bouton droit -->
                <Button
                    v-if="step === 1"
                    type="button"
                    size="sm"
                    :disabled="membres.length === 0"
                    @click="goToStep2"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>

                <Button
                    v-else-if="step === 2"
                    type="button"
                    size="sm"
                    :disabled="!partageValide"
                    @click="goToStep3"
                >
                    Suivant
                    <ChevronRight class="ml-1 h-4 w-4" />
                </Button>

                <Button
                    v-else
                    type="button"
                    size="sm"
                    :disabled="isSubmitting"
                    @click="submit"
                >
                    {{
                        isSubmitting
                            ? 'Enregistrement…'
                            : "Enregistrer l'équipe"
                    }}
                </Button>
            </div>
        </template>
    </Dialog>
</template>
