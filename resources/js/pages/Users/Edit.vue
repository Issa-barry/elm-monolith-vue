<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, KeyRound, Save, UserCog } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import UserForm from './partials/UserForm.vue';

interface RoleOption {
    value: string;
    label: string;
}

interface SiteOption {
    value: number;
    label: string;
}

interface UserData {
    id: number;
    prenom: string;
    nom: string;
    email: string;
    telephone: string | null;
    code_pays: string | null;
    ville: string | null;
    adresse: string | null;
    role: string;
    site_id: number | null;
    is_active: boolean;
}

const props = defineProps<{
    user: UserData;
    roles: RoleOption[];
    sites: SiteOption[];
    is_me: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Utilisateurs', href: '/users' },
    { title: `${props.user.prenom} ${props.user.nom}`, href: '#' },
];

const DIAL_MAP: Record<string, string> = {
    GN: '+224',
    GW: '+245',
    SN: '+221',
    ML: '+223',
    CI: '+225',
    LR: '+231',
    SL: '+232',
    FR: '+33',
    CN: '+86',
    AE: '+971',
    IN: '+91',
};

function localDigits(tel: string | null, codePays: string | null): string | null {
    if (!tel) return null;
    const dial = codePays ? DIAL_MAP[codePays] : null;
    if (dial && tel.startsWith(dial)) return tel.slice(dial.length);
    return tel;
}

const resolvedCodePays = props.user.code_pays ?? 'GN';

// ── Onglets ───────────────────────────────────────────────────────────────────
type Tab = 'info' | 'password';
const activeTab = ref<Tab>('info');

// ── Formulaire informations ───────────────────────────────────────────────────
const infoForm = useForm({
    prenom: props.user.prenom,
    nom: props.user.nom,
    email: props.user.email,
    telephone: localDigits(props.user.telephone, resolvedCodePays),
    code_pays: resolvedCodePays as string | null,
    code_phone_pays: DIAL_MAP[resolvedCodePays] ?? ('+224' as string | null),
    ville: props.user.ville,
    adresse: props.user.adresse,
    role: props.user.role,
    site_id: props.user.site_id,
    password: '',
    password_confirmation: '',
    is_active: props.user.is_active,
});

watch(() => props.user, (user) => {
    const codePays = user.code_pays ?? 'GN';
    infoForm.prenom   = user.prenom;
    infoForm.nom      = user.nom;
    infoForm.email    = user.email;
    infoForm.telephone = localDigits(user.telephone, codePays);
    infoForm.code_pays = codePays;
    infoForm.code_phone_pays = DIAL_MAP[codePays] ?? '+224';
    infoForm.ville    = user.ville;
    infoForm.adresse  = user.adresse;
    infoForm.role     = user.role;
    infoForm.site_id  = user.site_id;
    infoForm.is_active = user.is_active;
    infoForm.clearErrors();
}, { deep: true });

function submitInfo() {
    infoForm.put(`/users/${props.user.id}`);
}

// ── Formulaire mot de passe ───────────────────────────────────────────────────
const passwordForm = useForm({
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put(`/users/${props.user.id}/password`, {
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <Head>
        <title>Modifier — {{ user.prenom }} {{ user.nom }}</title>
    </Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/users"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ user.prenom }} {{ user.nom }}
                    </p>
                </div>
            </div>
        </div>

        <div class="pb-6 sm:p-6">
            <!-- Titre desktop -->
            <div class="hidden px-6 pt-6 pb-0 sm:block">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Modifier le compte
                    </h1>
                    <p class="mt-1 text-sm font-medium text-muted-foreground">
                        {{ user.prenom }} {{ user.nom }}
                        <span
                            v-if="is_me"
                            class="ml-1 rounded bg-muted px-1.5 py-0.5 text-[10px]"
                            >Moi</span
                        >
                    </p>
                </div>
            </div>

            <!-- Layout 2 colonnes style Settings -->
            <div class="flex flex-col px-4 sm:px-6 lg:flex-row lg:gap-12">

                <!-- Sidebar nav -->
                <aside class="w-full lg:w-44">
                    <nav class="flex gap-1 overflow-x-auto pb-4 lg:flex-col lg:space-y-1 lg:overflow-x-visible lg:pb-0">
                        <button
                            type="button"
                            class="inline-flex w-auto shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted lg:w-full lg:justify-start"
                            :class="activeTab === 'info' ? 'bg-muted text-foreground' : 'text-muted-foreground'"
                            @click="activeTab = 'info'"
                        >
                            <UserCog class="h-4 w-4" />
                            Informations
                        </button>
                        <button
                            type="button"
                            class="inline-flex w-auto shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted lg:w-full lg:justify-start"
                            :class="activeTab === 'password' ? 'bg-muted text-foreground' : 'text-muted-foreground'"
                            @click="activeTab = 'password'"
                        >
                            <KeyRound class="h-4 w-4" />
                            Mot de passe
                        </button>
                    </nav>
                </aside>

                <!-- Contenu -->
                <div class="min-w-0 flex-1">

                <!-- Onglet Informations -->
                <UserForm
                    v-if="activeTab === 'info'"
                    :form="infoForm"
                    :errors="infoForm.errors"
                    :processing="infoForm.processing"
                    :roles="roles"
                    :sites="sites"
                    :is-edit="true"
                    :show-password="false"
                    back-href="/users"
                    @submit="submitInfo"
                    @update:form="Object.assign(infoForm, $event)"
                    @clear-error="infoForm.clearErrors($event as any)"
                />

            <!-- Onglet Mot de passe -->
                <form
                    v-else
                    id="user-form"
                    class="space-y-4 sm:space-y-6"
                    autocomplete="off"
                    @submit.prevent="submitPassword"
                >
                    <div class="rounded-xl border bg-card p-4 shadow-sm sm:p-6">
                        <h3
                            class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"
                        >
                            Nouveau mot de passe
                        </h3>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label
                                    for="password"
                                    class="mb-1.5 block text-sm font-medium"
                                >
                                    Mot de passe
                                    <span class="text-destructive">*</span>
                                </label>
                                <input
                                    id="password"
                                    v-model="passwordForm.password"
                                    type="password"
                                    autocomplete="new-password"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :class="{ 'border-destructive': passwordForm.errors.password }"
                                />
                                <p
                                    v-if="passwordForm.errors.password"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ passwordForm.errors.password }}
                                </p>
                            </div>
                            <div>
                                <label
                                    for="password_confirmation"
                                    class="mb-1.5 block text-sm font-medium"
                                >
                                    Confirmer
                                    <span class="text-destructive">*</span>
                                </label>
                                <input
                                    id="password_confirmation"
                                    v-model="passwordForm.password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Actions desktop -->
                    <div class="hidden items-center justify-between sm:flex">
                        <Link href="/users">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium transition-colors hover:bg-accent"
                            >
                                <ArrowLeft class="mr-2 h-4 w-4" />
                                Retour
                            </button>
                        </Link>
                        <button
                            type="submit"
                            :disabled="passwordForm.processing"
                            class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
                        >
                            <Save class="mr-2 h-4 w-4" />
                            {{ passwordForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                        </button>
                    </div>
                    <div class="h-20 sm:hidden" />
                </form>

                </div><!-- /flex-1 -->
            </div><!-- /flex row -->
        </div>

        <!-- Footer mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="user-form"
                :disabled="activeTab === 'info' ? infoForm.processing : passwordForm.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner
                    v-if="activeTab === 'info' ? infoForm.processing : passwordForm.processing"
                    class="h-4 w-4"
                />
                <Save v-else class="h-4 w-4" />
                {{
                    (activeTab === 'info' ? infoForm.processing : passwordForm.processing)
                        ? 'Enregistrement…'
                        : 'Enregistrer'
                }}
            </button>
        </div>
    </AppLayout>
</template>
