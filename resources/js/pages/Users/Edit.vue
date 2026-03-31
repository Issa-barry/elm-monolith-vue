<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import UserForm from './partials/UserForm.vue';

interface RoleOption {
    value: string;
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
    is_active: boolean;
}

const props = defineProps<{
    user: UserData;
    roles: RoleOption[];
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

// If telephone is stored as E.164 ("+224…"), strip the dial prefix for the input
function localDigits(
    tel: string | null,
    codePays: string | null,
): string | null {
    if (!tel) return null;
    const dial = codePays ? DIAL_MAP[codePays] : null;
    if (dial && tel.startsWith(dial)) return tel.slice(dial.length);
    return tel;
}

const resolvedCodePays = props.user.code_pays ?? 'GN';

const form = useForm({
    prenom: props.user.prenom,
    nom: props.user.nom,
    email: props.user.email,
    telephone: localDigits(props.user.telephone, resolvedCodePays),
    code_pays: resolvedCodePays as string | null,
    code_phone_pays: DIAL_MAP[resolvedCodePays] ?? ('+224' as string | null),
    ville: props.user.ville,
    adresse: props.user.adresse,
    role: props.user.role,
    password: '',
    password_confirmation: '',
    is_active: props.user.is_active,
});

function submit() {
    form.put(`/users/${props.user.id}`);
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

        <div class="mx-auto max-w-2xl pb-6 sm:p-6">
            <div class="mx-auto hidden max-w-2xl px-6 pt-6 pb-0 sm:block">
                <div class="mb-8">
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

            <UserForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :roles="roles"
                :is-edit="true"
                back-href="/users"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
                @clear-error="form.clearErrors($event as any)"
            />
        </div>

        <!-- Footer mobile -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="user-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
        </div>
    </AppLayout>
</template>
