import { usePage } from '@inertiajs/react';

// Admin-editable site settings shared with every page
// (HandleInertiaRequests → 'site'; edited in the admin's Settings page).
export function useSite() {
    return usePage().props.site ?? {};
}

// A switch that isn't listed counts as on, so an older cached page never
// hides a working feature.
export function isOn(site, feature) {
    return site?.switches?.[feature] !== false;
}
