// SWC.1 (ADR 0073 section 6): the shared `navigation` prop built by
// App\Support\Navigation\NavigationBuilder. The client renders only what the
// server sent; it holds no capability logic of its own.
export interface NavigationItem {
    key: string;
    label: string;
    href: string;
    active: boolean;
}

export interface NavigationGroupItem extends NavigationItem {
    section: string | null;
}

export interface NavigationGroup {
    key: string;
    label: string;
    items: NavigationGroupItem[];
}

export interface Navigation {
    home: { href: string; active: boolean };
    school: { name: string } | null;
    groups: NavigationGroup[];
    account: NavigationItem[];
    activeKey: string | null;
}

/** Groups outside School authority, rendered in their own landmark. */
export const PLATFORM_GROUP_KEYS = ['school-groups', 'platform'];
export const GUARDIAN_GROUP_KEYS = ['guardian-portal'];
