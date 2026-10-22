import type { PageProps } from '@inertiajs/core';

export interface AuthUser {
    id: number;
    name: string;
    nickname: string | null;
    email: string | null;
    avatar_url: string | null;
    is_guest: boolean;
    email_verified: boolean;
    two_factor_enabled: boolean;
    roles: string[];
    permissions: string[];
    is_super_admin: boolean;
    current_team_id: number | null;
}

export interface TeamSummary {
    id: number;
    name: string;
}

export interface NavigationItem {
    label: string;
    href: string;
    permission: string | null;
}

export interface Branding {
    name: string;
    organisation_name: string | null;
    service_name: string | null;
    contact_email: string | null;
    version: string;
    logo_url: string | null;
    launched_on: string | null;
}

export interface LegalDocument {
    slug: string;
    title: string;
    body: string;
    version: number;
    revision: number;
    published_at: string | null;
}
export interface ConsentConfig {
    version: number;
    enabled: boolean;
    expiry_days: number;
    analytics_enabled: boolean;
    marketing_enabled: boolean;
    accept_label: string;
    reject_label: string;
    save_label: string;
    notices: { id: string; enabled: boolean; title: string; body: string }[];
}
export interface SharedPageProps extends PageProps {
    legalLinks: { title: string; href: string }[];
    aboutBoilerplateUrl: string | null;
    boilerplateCredit: string | null;
    consent: {
        config: ConsentConfig;
        choice: { id: string; version: number; necessary: boolean; analytics: boolean; marketing: boolean; expires_at: number } | null;
    };
    analytics: {
        google_measurement_id: string | null;
    };
    branding: Branding;
    theme: string;
    canRegister: boolean;
    canStartGuest: boolean;
    navigation: NavigationItem[];
    navigationSettingsUrl: string | null;
    siteMenu: { label: string; href: string }[];
    siteLanguages: SiteLanguages | null;
    assistantEnabled: boolean;
    assistantWidget: AssistantWidgetConfig | null;
    developerPortalVisible?: boolean;
    auth: {
        user: AuthUser | null;
    };
    teams: TeamSummary[];
    status: string | null;
}

export interface AssistantWidgetConfig {
    base_url: string;
    integration_id: string;
    claim_endpoint: string | null;
    revision: string;
}

/**
 * Languages visitors can switch public pages to (Admin → Languages).
 */
export interface SiteLanguages {
    source: string;
    languages: { code: string; name: string; native: string; dir: 'ltr' | 'rtl' }[];
    version: string;
}

export interface OAuthProviderOption {
    provider: string;
    label: string;
}

export type FormFieldTypeValue = 'text' | 'textarea' | 'email' | 'phone' | 'number' | 'date' | 'url' | 'select' | 'radio' | 'checkboxes' | 'checkbox' | 'heading';

export type FormStatusValue = 'draft' | 'published' | 'closed';

/**
 * One field in a form-builder form, as saved on the server.
 */
export interface FormFieldDefinition {
    id: string;
    key: string;
    type: FormFieldTypeValue;
    label: string;
    hint: string | null;
    placeholder: string | null;
    required: boolean;
    options: string[];
    min: number | null;
    max: number | null;
}

export interface FormFieldTypeOption {
    value: FormFieldTypeValue;
    label: string;
    description: string;
    has_options: boolean;
    collects_input: boolean;
}

/**
 * An answer to one field: text, a list of ticked options, or a yes/no tick.
 */
export type FormAnswer = string | string[] | boolean | null;

export interface FormSummary {
    id: number;
    title: string;
    slug: string | null;
    description: string | null;
    status: FormStatusValue;
    is_registration: boolean;
    team: { id: number; name: string | null } | null;
    public_url: string | null;
    submit_label: string | null;
    success_message: string | null;
    max_submissions: number | null;
    closes_at: string | null;
    sends_confirmation: boolean;
    layout: FormLayoutValue;
    media: FormMedia | null;
    submissions_count?: number;
    updated_at: string | null;
}

export interface FormTemplateOption {
    value: string;
    label: string;
    description: string;
}

export type FormLayoutValue = 'simple' | 'designed';

/**
 * The banner on a form's public page.
 */
export interface FormMedia {
    type: 'image' | 'video';
    url: string;
}

export interface BlogPostSummary {
    id: number;
    title: string;
    slug: string;
    summary: string;
    status: 'draft' | 'published';
    is_scheduled: boolean;
    published_at: string | null;
    updated_at: string | null;
    cover_url: string | null;
    reading_minutes: number;
    author?: { name: string } | null;
    categories?: BlogCategoryLink[];
}

/**
 * A blog category as shown on posts and in the category links.
 */
export interface BlogCategoryLink {
    id?: number;
    name: string;
    slug: string;
}

/**
 * A Laravel API-resource page: `data`, plus `links` and `meta` from the paginator.
 */
export interface PaginatedResource<T> {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
}
