<script setup lang="ts">
// ORG.12C (ADR 0080 §8): one Campus's profile -- identity, address, contact,
// location and services -- and, for a Campus manager, its edit form. The
// server validates everything. Services describe what the Campus offers:
// they never enable a module or give anyone access. Coordinates are entered
// by hand (no map lookup) and are both set or both empty.
import { Head, useForm } from '@inertiajs/vue3';
import CheckboxField from '../../../Components/Form/CheckboxField.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Campus {
    id: string;
    name: string;
    code: string;
    status: string;
    phone: string | null;
    email: string | null;
    addressLine1: string | null;
    addressLine2: string | null;
    city: string | null;
    stateRegion: string | null;
    postalCode: string | null;
    countryCode: string | null;
    latitude: string | null;
    longitude: string | null;
    services: string[];
}

const props = defineProps<{
    campus: Campus;
    schoolCountryCode: string;
    serviceOptions: Array<{ code: string; label: string }>;
    canManage: boolean;
}>();

const form = useForm({
    name: props.campus.name,
    code: props.campus.code,
    phone: props.campus.phone ?? '',
    email: props.campus.email ?? '',
    address_line1: props.campus.addressLine1 ?? '',
    address_line2: props.campus.addressLine2 ?? '',
    city: props.campus.city ?? '',
    state_region: props.campus.stateRegion ?? '',
    postal_code: props.campus.postalCode ?? '',
    country_code: props.campus.countryCode ?? '',
    latitude: props.campus.latitude ?? '',
    longitude: props.campus.longitude ?? '',
    services: [...props.campus.services],
});

function toggleService(code: string, on: boolean | undefined) {
    form.services = on
        ? [...new Set([...form.services, code])]
        : form.services.filter((service) => service !== code);
}

function save() {
    form.put(`/app/school-setup/campuses/${props.campus.id}`, { preserveScroll: true });
}

const serviceLabel = (code: string) =>
    props.serviceOptions.find((option) => option.code === code)?.label ?? code;
const show = (value: string | null | undefined) => (value && value !== '' ? value : '—');
</script>

<template>
    <Head :title="campus.name" />
    <main class="mx-auto max-w-3xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup/campuses">← Campuses</a>
        <h1 class="mt-2 text-xl font-semibold">{{ campus.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">
            Code {{ campus.code }} · {{ campus.status === 'active' ? 'Active' : 'Inactive' }}
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <section aria-labelledby="address-heading" class="rounded border border-slate-200 p-4">
                <h2 id="address-heading" class="text-sm font-semibold">Address</h2>
                <address class="mt-2 text-sm not-italic break-words">
                    <span v-if="campus.addressLine1" class="block">{{ campus.addressLine1 }}</span>
                    <span v-if="campus.addressLine2" class="block">{{ campus.addressLine2 }}</span>
                    <span class="block">{{
                        [campus.city, campus.stateRegion, campus.postalCode]
                            .filter(Boolean)
                            .join(', ') || '—'
                    }}</span>
                    <span class="block">{{ campus.countryCode ?? schoolCountryCode }}</span>
                </address>
            </section>
            <section aria-labelledby="contact-heading" class="rounded border border-slate-200 p-4">
                <h2 id="contact-heading" class="text-sm font-semibold">Contact</h2>
                <dl class="mt-2 space-y-1 text-sm break-words">
                    <div>
                        <dt class="inline text-slate-500">Phone:</dt>
                        <dd class="inline">{{ show(campus.phone) }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Email:</dt>
                        <dd class="inline">{{ show(campus.email) }}</dd>
                    </div>
                </dl>
            </section>
            <section aria-labelledby="location-heading" class="rounded border border-slate-200 p-4">
                <h2 id="location-heading" class="text-sm font-semibold">Location</h2>
                <p class="mt-2 text-sm">
                    {{
                        campus.latitude !== null
                            ? `${campus.latitude}, ${campus.longitude}`
                            : 'No coordinates recorded'
                    }}
                </p>
            </section>
            <section aria-labelledby="services-heading" class="rounded border border-slate-200 p-4">
                <h2 id="services-heading" class="text-sm font-semibold">Services offered</h2>
                <ul v-if="campus.services.length > 0" class="mt-2 list-inside list-disc text-sm">
                    <li v-for="code in campus.services" :key="code">{{ serviceLabel(code) }}</li>
                </ul>
                <p v-else class="mt-2 text-sm">None recorded</p>
            </section>
        </div>

        <section v-if="canManage" aria-labelledby="edit-heading" class="mt-8">
            <h2 id="edit-heading" class="text-base font-semibold">Edit this Campus</h2>
            <ErrorSummary :errors="form.errors" :fields="Object.keys(form.errors)" />
            <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                <TextField
                    id="name"
                    v-model="form.name"
                    label="Name"
                    required
                    :error="form.errors.name"
                />
                <TextField
                    id="code"
                    v-model="form.code"
                    label="Code"
                    required
                    :maxlength="32"
                    :error="form.errors.code"
                />
                <TextField
                    id="phone"
                    v-model="form.phone"
                    type="tel"
                    label="Phone"
                    hint="International format, e.g. +14155550123"
                    :error="form.errors.phone"
                />
                <TextField
                    id="email"
                    v-model="form.email"
                    type="email"
                    label="Email"
                    :error="form.errors.email"
                />
                <TextField
                    id="address_line1"
                    v-model="form.address_line1"
                    class="sm:col-span-2"
                    label="Address line 1"
                    :error="form.errors.address_line1"
                />
                <TextField
                    id="address_line2"
                    v-model="form.address_line2"
                    class="sm:col-span-2"
                    label="Address line 2"
                    :error="form.errors.address_line2"
                />
                <TextField
                    id="city"
                    v-model="form.city"
                    label="City or locality"
                    :error="form.errors.city"
                />
                <TextField
                    id="state_region"
                    v-model="form.state_region"
                    label="State, province or region"
                    :error="form.errors.state_region"
                />
                <TextField
                    id="postal_code"
                    v-model="form.postal_code"
                    label="Postal code"
                    :error="form.errors.postal_code"
                />
                <TextField
                    id="country_code"
                    v-model="form.country_code"
                    label="Country code"
                    :hint="`Two letters; leave empty for the School's country (${schoolCountryCode})`"
                    :maxlength="2"
                    :error="form.errors.country_code"
                />
                <TextField
                    id="latitude"
                    v-model="form.latitude"
                    label="Latitude"
                    hint="Decimal degrees, -90 to 90 (set both or neither)"
                    :error="form.errors.latitude"
                />
                <TextField
                    id="longitude"
                    v-model="form.longitude"
                    label="Longitude"
                    hint="Decimal degrees, -180 to 180"
                    :error="form.errors.longitude"
                />
                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium">Services offered</legend>
                    <p class="text-xs text-slate-500">
                        Describes this Campus. It does not enable a module or give anyone access.
                    </p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <CheckboxField
                            v-for="option in serviceOptions"
                            :id="`service-${option.code}`"
                            :key="option.code"
                            :label="option.label"
                            :model-value="form.services.includes(option.code)"
                            @update:model-value="(on) => toggleService(option.code, on)"
                        />
                    </div>
                    <p v-if="form.errors.services" class="mt-1 text-sm text-red-700">
                        {{ form.errors.services }}
                    </p>
                </fieldset>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Save Campus
                    </button>
                </div>
            </form>
        </section>
        <p v-else class="mt-8 text-sm text-slate-600">You have read-only access to this Campus.</p>
    </main>
</template>
