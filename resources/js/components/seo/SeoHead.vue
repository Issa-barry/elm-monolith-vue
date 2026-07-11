<script setup lang="ts">
import {
    absoluteUrl,
    buildLocalBusinessJsonLd,
    buildMetaTitle,
    buildOrganizationJsonLd,
    buildWebsiteJsonLd,
} from '@/lib/seo';
import type { AppPageProps } from '@/types';
import type { SeoHeadProps } from '@/types/seo';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(defineProps<SeoHeadProps>(), {
    image: undefined,
    path: undefined,
    canonical: undefined,
    robots: 'index,follow',
    type: 'website',
    organization: true,
    localBusiness: false,
});

const page = usePage<AppPageProps>();
const defaults = computed(() => page.props.seoDefaults);

// `resources/js/app.ts` / `ssr.ts` already append " - {appName}" to every
// <title> via createInertiaApp's `title` resolver — the raw page title goes
// there untouched. og:title/twitter:title are read independently by link
// preview scrapers (they never see the <title> tag), so they get their own
// branded suffix here.
const ogTitle = computed(() =>
    buildMetaTitle(props.title, defaults.value.siteName),
);

const currentPath = computed(() => props.path ?? page.url ?? '/');

const canonicalUrl = computed(() =>
    props.canonical ?? absoluteUrl(defaults.value.baseUrl, currentPath.value),
);

const ogImage = computed(() =>
    absoluteUrl(defaults.value.baseUrl, props.image ?? defaults.value.defaultImage),
);

const jsonLd = computed(() => {
    const blocks: Record<string, unknown>[] = [];

    if (props.organization) {
        blocks.push(buildOrganizationJsonLd(defaults.value));
        blocks.push(buildWebsiteJsonLd(defaults.value));
    }

    if (props.localBusiness) {
        blocks.push(buildLocalBusinessJsonLd(defaults.value));
    }

    return blocks;
});
</script>

<template>
    <Head :title="title">
        <meta name="description" :content="description" />
        <meta name="robots" :content="robots" />
        <link rel="canonical" :href="canonicalUrl" />

        <meta property="og:title" :content="ogTitle" />
        <meta property="og:description" :content="description" />
        <meta property="og:type" :content="type" />
        <meta property="og:url" :content="canonicalUrl" />
        <meta property="og:image" :content="ogImage" />
        <meta property="og:site_name" :content="defaults.siteName" />
        <meta property="og:locale" :content="defaults.locale" />

        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" :content="ogTitle" />
        <meta name="twitter:description" :content="description" />
        <meta name="twitter:image" :content="ogImage" />

        <!--
          Vue's compiler strips literal <script> tags found inside a template
          (treated as a side-effect tag, same rule that keeps SFC <script>
          blocks from being confused with template content). `component :is`
          resolves the tag dynamically at render time and bypasses that check,
          which is what actually gets the JSON-LD block into the DOM.
        -->
        <component
            v-for="(block, index) in jsonLd"
            :key="index"
            :is="'script'"
            type="application/ld+json"
        >{{ JSON.stringify(block) }}</component>
    </Head>
</template>
