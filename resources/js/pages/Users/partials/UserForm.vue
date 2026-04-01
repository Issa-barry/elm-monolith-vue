<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { paysOptionsByCode } from '@/lib/pays';
import { ArrowLeft, ArrowRight, Save } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed } from 'vue';

interface RoleOption {
    value: string;
    label: string;
}

interface SiteOption {
    value: number;
    label: string;
}

const PAYS_OPTIONS = paysOptionsByCode;

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

const props = defineProps<{
    form: {
        prenom: string;
        nom: string;
        email: string;
        telephone: string | null;
        code_pays: string | null;
        code_phone_pays: string | null;
        ville: string | null;
        adresse: string | null;
        role: string;
        password: string;
        password_confirmation: string;
        is_active: boolean;
    };
    errors: Record<string, string>;
    processing: boolean;
    roles: RoleOption[];
    sites?: SiteOption[];
    isEdit?: boolean;
    showPassword?: boolean;
    submitLabel?: string;
    backHref?: string;
}>();

const emit = defineEmits<{
    submit: [];
    'update:form': [form: typeof props.form];
    'clear-error': [field: string];
}>();

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur',
    manager: 'Manager',
    commerciale: 'Commercial(e)',
    comptable: 'Comptable',
};

const roleOptions = computed(() =>
    props.roles.map((r) => ({
        value: r.value,
        label: ROLE_LABELS[r.value] ?? r.value,
    })),
);

const selectedCountry = computed(
    () =>
        PAYS_OPTIONS.find((c) => c.code === props.form.code_pays) ??
        PAYS_OPTIONS[0],
);

const selectedPhoneLength = computed(() => selectedCountry.value.localLength);

const phoneMaxLength = computed(() => {
    const digits = String(props.form.telephone ?? '').replaceAll(/\D/g, '');
    return digits.startsWith('0')
        ? selectedPhoneLength.value + 1
        : selectedPhoneLength.value;
});

function onPaysChange(code: string) {
    const country = PAYS_OPTIONS.find((c) => c.code === code);
    if (!country) return;
    const currentDigits = String(props.form.telephone ?? '').replaceAll(
        /\D/g,
        '',
    );
    const max = currentDigits.startsWith('0')
        ? country.localLength + 1
        : country.localLength;
    emit('update:form', {
        ...props.form,
        code_pays: country.code,
        code_phone_pays: country.dial,
        telephone: currentDigits.slice(0, max) || null,
        ville:
            country.code === 'GN' && !props.form.ville
                ? 'Conakry'
                : props.form.ville,
    });
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
        'ArrowUp',
        'ArrowDown',
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

function onTelephoneInput(value: string | null | undefined) {
    const raw = String(value ?? '').replaceAll(/\D/g, '');
    const max = raw.startsWith('0')
        ? selectedPhoneLength.value + 1
        : selectedPhoneLength.value;
    emit('update:form', {
        ...props.form,
        telephone: raw.slice(0, max) || null,
    });
    emit('clear-error', 'telephone');
}

function update(field: keyof typeof props.form, value: string | null) {
    emit('update:form', { ...props.form, [field]: value });
    emit('clear-error', field);
}

function toTitleCase(str: string): string {
    return str
        .toLowerCase()
        .replaceAll(/(?:^|\s|-)\S/g, (c) => c.toUpperCase());
}

function formatOnBlur(field: 'prenom' | 'nom' | 'ville' | 'adresse' | 'email') {
    const raw = (props.form[field] as string | null) ?? '';
    if (!raw.trim()) return;
    let formatted: string;
    if (field === 'nom') formatted = raw.toUpperCase();
    else if (field === 'prenom' || field === 'ville' || field === 'adresse')
        formatted = toTitleCase(raw);
    else formatted = raw.toLowerCase(); // email
    emit('update:form', { ...props.form, [field]: formatted });
}
</script>

<template>
    <form
        id="user-form"
        class="space-y-4 sm:space-y-6"
        autocomplete="off"
        @submit.prevent="emit('submit')"
    >
        <!-- Identité -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Identité
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="prenom" class="mb-1.5 block">
                        Prénom <span class="text-destructive">*</span>
                    </Label>
                    <div
                        @focusout="formatOnBlur('prenom')"
                        @keydown.enter="formatOnBlur('prenom')"
                    >
                        <InputText
                            id="prenom"
                            :model-value="form.prenom"
                            autocomplete="off"
                            class="w-full"
                            :class="{ 'p-invalid': errors.prenom }"
                            @update:model-value="
                                update('prenom', String($event ?? ''))
                            "
                        />
                    </div>
                    <p
                        v-if="errors.prenom"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.prenom }}
                    </p>
                </div>
                <div>
                    <Label for="nom" class="mb-1.5 block">
                        Nom <span class="text-destructive">*</span>
                    </Label>
                    <div
                        @focusout="formatOnBlur('nom')"
                        @keydown.enter="formatOnBlur('nom')"
                    >
                        <InputText
                            id="nom"
                            :model-value="form.nom"
                            autocomplete="off"
                            class="w-full"
                            :class="{ 'p-invalid': errors.nom }"
                            @update:model-value="
                                update('nom', String($event ?? ''))
                            "
                        />
                    </div>
                    <p v-if="errors.nom" class="mt-1 text-xs text-destructive">
                        {{ errors.nom }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Localisation -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Localisation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="code_pays" class="mb-1.5 block">Pays</Label>
                    <Dropdown
                        input-id="code_pays"
                        :model-value="form.code_pays"
                        :options="PAYS_OPTIONS"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        @update:model-value="onPaysChange($event)"
                    >
                        <template #value="{ value }">
                            <div v-if="value" class="flex items-center gap-2">
                                <img
                                    :src="flagUrl(value)"
                                    :alt="selectedCountry.label"
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <span>{{ selectedCountry.label }}</span>
                            </div>
                            <span v-else class="text-muted-foreground">
                                Sélectionner…
                            </span>
                        </template>
                        <template #option="{ option }">
                            <div class="flex items-center gap-2">
                                <img
                                    :src="flagUrl(option.code)"
                                    :alt="option.label"
                                    class="h-4 w-auto rounded-sm shadow-sm"
                                />
                                <span>{{ option.label }}</span>
                            </div>
                        </template>
                    </Dropdown>
                </div>
                <div>
                    <Label for="ville" class="mb-1.5 block">Ville</Label>
                    <div
                        @focusout="formatOnBlur('ville')"
                        @keydown.enter="formatOnBlur('ville')"
                    >
                        <InputText
                            id="ville"
                            :model-value="form.ville ?? ''"
                            class="w-full"
                            @update:model-value="
                                update('ville', ($event as string) || null)
                            "
                        />
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <Label for="adresse" class="mb-1.5 block">Adresse</Label>
                    <div
                        @focusout="formatOnBlur('adresse')"
                        @keydown.enter="formatOnBlur('adresse')"
                    >
                        <InputText
                            id="adresse"
                            :model-value="form.adresse ?? ''"
                            class="w-full"
                            @update:model-value="
                                update('adresse', ($event as string) || null)
                            "
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Contact
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <!-- Téléphone -->
                <div>
                    <Label for="telephone" class="mb-1.5 block">
                        Téléphone <span class="text-destructive">*</span>
                    </Label>
                    <div class="flex gap-2">
                        <div
                            class="flex h-10 w-24 shrink-0 items-center justify-center gap-1.5 rounded-md border bg-muted/40 px-2 font-mono text-sm text-muted-foreground"
                        >
                            <img
                                v-if="form.code_pays"
                                :src="flagUrl(form.code_pays)"
                                :alt="selectedCountry.label"
                                class="h-4 w-auto rounded-sm shadow-sm"
                            />
                            <span>{{ selectedCountry.dial }}</span>
                        </div>
                        <InputText
                            id="telephone"
                            :model-value="form.telephone ?? ''"
                            placeholder=""
                            inputmode="numeric"
                            pattern="[0-9]*"
                            :maxlength="phoneMaxLength"
                            class="w-full"
                            :class="{ 'p-invalid': errors.telephone }"
                            @update:model-value="onTelephoneInput($event)"
                            @keydown="handlePhoneKeydown"
                        />
                    </div>
                    <p
                        v-if="errors.telephone"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.telephone }}
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground">
                        Saisissez les chiffres sans indicatif
                    </p>
                </div>

                <!-- Email -->
                <div>
                    <Label for="email" class="mb-1.5 block">
                        Adresse e-mail
                        <span class="text-xs text-muted-foreground"
                            >(facultatif)</span
                        >
                    </Label>
                    <InputText
                        id="email"
                        :model-value="form.email ?? ''"
                        type="email"
                        autocomplete="off"
                        class="w-full"
                        :class="{ 'p-invalid': errors.email }"
                        @update:model-value="
                            update('email', ($event as string) || null)
                        "
                        @focusout="formatOnBlur('email')"
                        @keydown.enter="formatOnBlur('email')"
                    />
                    <p
                        v-if="errors.email"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.email }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Rôle & Site -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Rôle & Affectation
            </h3>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="role" class="mb-1.5 block">
                        Rôle <span class="text-destructive">*</span>
                    </Label>
                    <Select
                        input-id="role"
                        :model-value="form.role"
                        :options="roleOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir un rôle"
                        class="w-full"
                        :class="{ 'p-invalid': errors.role }"
                        @change="update('role', $event.value)"
                    />
                    <p v-if="errors.role" class="mt-1 text-xs text-destructive">
                        {{ errors.role }}
                    </p>
                </div>
                <div v-if="sites && sites.length">
                    <Label for="site_id" class="mb-1.5 block">
                        Site <span class="text-destructive">*</span>
                    </Label>
                    <Select
                        input-id="site_id"
                        :model-value="(form as any).site_id"
                        :options="sites"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir un site"
                        class="w-full"
                        :class="{ 'p-invalid': errors.site_id }"
                        @change="
                            emit('update:form', {
                                ...form,
                                site_id: $event.value,
                            } as any)
                        "
                    />
                    <p
                        v-if="errors.site_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.site_id }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Mot de passe -->
        <div
            v-if="showPassword !== false"
            class="rounded-xl border bg-card p-4 shadow-sm sm:p-6"
        >
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                {{ isEdit ? 'Changer le mot de passe' : 'Mot de passe' }}
            </h3>
            <p v-if="isEdit" class="mb-4 text-xs text-muted-foreground">
                Laissez vide pour conserver le mot de passe actuel.
            </p>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <Label for="password" class="mb-1.5 block">
                        Mot de passe
                        <span v-if="!isEdit" class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="password"
                        :model-value="form.password"
                        type="password"
                        autocomplete="new-password"
                        class="w-full"
                        :class="{ 'p-invalid': errors.password }"
                        @update:model-value="
                            update('password', String($event ?? ''))
                        "
                    />
                    <p
                        v-if="errors.password"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ errors.password }}
                    </p>
                </div>
                <div>
                    <Label for="password_confirmation" class="mb-1.5 block">
                        Confirmer
                        <span v-if="!isEdit" class="text-destructive">*</span>
                    </Label>
                    <InputText
                        id="password_confirmation"
                        :model-value="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        class="w-full"
                        @update:model-value="
                            update(
                                'password_confirmation',
                                String($event ?? ''),
                            )
                        "
                    />
                </div>
            </div>
        </div>

        <!-- Statut -->
        <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
            >
                Statut
            </h3>
            <div class="flex items-center gap-3">
                <Checkbox
                    id="is_active"
                    :model-value="form.is_active"
                    @update:model-value="
                        emit('update:form', {
                            ...form,
                            is_active: $event === true,
                        })
                    "
                />
                <div>
                    <Label for="is_active" class="cursor-pointer font-medium">
                        {{ form.is_active ? 'Actif' : 'Inactif' }}
                    </Label>
                    <p class="text-xs text-muted-foreground">
                        {{
                            form.is_active
                                ? 'Décochez pour désactiver ce compte'
                                : 'Cochez pour activer ce compte'
                        }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Actions desktop -->
        <div class="hidden items-center justify-between sm:flex">
            <Button variant="outline" type="button" as-child>
                <a :href="backHref ?? '/users'">
                    <ArrowLeft class="mr-2 h-4 w-4" />
                    Retour
                </a>
            </Button>
            <Button type="submit" :disabled="processing">
                <component
                    :is="submitLabel ? ArrowRight : Save"
                    class="mr-2 h-4 w-4"
                />
                {{
                    processing
                        ? 'Enregistrement…'
                        : submitLabel
                          ? submitLabel
                          : isEdit
                            ? 'Enregistrer'
                            : 'Créer le compte'
                }}
            </Button>
        </div>
        <div class="h-20 sm:hidden" />
    </form>
</template>
