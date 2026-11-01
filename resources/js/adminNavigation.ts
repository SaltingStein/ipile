/**
 * Admin pages grouped by purpose. Shared by the admin sidebar, the admin overview and the workspace sidebar so all three stay in sync.
 * Each page lists the permissions that open it; the routes enforce the same rules.
 */
export interface AdminSection {
    title: string;
    href: string;
    description: string;
}

export interface AdminGroup {
    title: string;
    description: string;
    sections: AdminSection[];
}

interface AdminSectionDefinition extends AdminSection {
    permissions: string[];
}

interface AdminGroupDefinition {
    title: string;
    description: string;
    sections: AdminSectionDefinition[];
}

const adminGroupDefinitions: AdminGroupDefinition[] = [
    {
        title: 'Appearance',
        description: 'How your site looks, what people see in the menu and which languages it speaks.',
        sections: [
            { title: 'Branding', href: '/admin/branding', description: 'App name, logo, version, launch date and the public About page.', permissions: ['manage_settings'] },
            { title: 'Themes', href: '/admin/theme', description: 'Site-wide colours and the landing page layout.', permissions: ['manage_settings'] },
            { title: 'Menus', href: '/admin/navigation', description: 'Public site menu links and extra workspace sidebar links.', permissions: ['manage_settings'] },
            { title: 'Languages', href: '/admin/languages', description: 'Offer your public pages in other languages, with automatic or hand-written translations.', permissions: ['manage_translations'] },
        ],
    },
    {
        title: 'Post manager',
        description: 'News, stories and announcements.',
        sections: [
            { title: 'Blog', href: '/admin/blog', description: 'Switch the blog on or off, sort posts into categories and write, schedule and publish them.', permissions: ['manage_blog'] },
        ],
    },
    {
        title: 'Forms',
        description: 'Collect sign-ups, registrations and feedback without code.',
        sections: [
            { title: 'Forms', href: '/admin/forms', description: 'Drag-and-drop site forms and extra sign-up questions.', permissions: ['manage_forms'] },
        ],
    },
    {
        title: 'Campaigns',
        description: 'Landing pages for launches, events and promotions.',
        sections: [
            { title: 'Marketing page', href: '/admin/marketing', description: 'Enable the free Ìpìlẹ̀ community homepage and set its GitHub link.', permissions: ['manage_settings'] },
            { title: 'Campaign pages', href: '/admin/campaigns', description: 'Build landing pages from a template with banners, videos, buttons and forms.', permissions: ['manage_campaigns'] },
        ],
    },
    {
        title: 'Security',
        description: 'Who can sign in, what they can do, and Aegis threat monitoring.',
        sections: [
            { title: 'Roles & permissions', href: '/admin/access', description: 'Assign roles to users and choose what each role can do.', permissions: ['manage_access'] },
            { title: 'Sign-in & 2FA', href: '/admin/authentication', description: 'Registration, two-factor rules, trusted devices, API token lifetimes and social sign-in.', permissions: ['manage_settings'] },
            { title: 'Threat monitoring', href: '/admin/security', description: 'Aegis incidents and recent high-risk events.', permissions: ['view_security', 'manage_security'] },
            { title: 'Incidents', href: '/admin/security/incidents', description: 'Every incident, including site assistant violations: review, restore suspended accounts and resolve.', permissions: ['view_security', 'manage_security'] },
            { title: 'Monitoring settings', href: '/admin/security/settings', description: 'Aegis mode, response thresholds, scope, data retention and the AI analyst.', permissions: ['manage_security'] },
            { title: 'Monitoring guide', href: '/admin/security/guide', description: 'How Aegis works, the server checklist and setup steps.', permissions: ['view_security', 'manage_security'] },
        ],
    },
    {
        title: 'Platform',
        description: 'Connected services, outgoing messages and the site assistant.',
        sections: [
            { title: 'Integrations', href: '/admin/integrations', description: 'Payments, SMS, email, analytics and AI providers.', permissions: ['manage_integrations'] },
            { title: 'Notifications', href: '/admin/notifications', description: 'Email and SMS for sign-ups, security alerts, invitations and payments.', permissions: ['manage_settings'] },
            { title: 'Site assistant', href: '/admin/assistant', description: 'The AI help assistant for signed-in people, its staff modes, the business figures it may read, and its fixed guard rails.', permissions: ['manage_settings'] },
            { title: 'Assistant activity', href: '/admin/assistant/activity', description: 'Every assistant request: who asked, what they were allowed, the tools used and the outcome.', permissions: ['view_ai_activity'] },
        ],
    },
    {
        title: 'Developers',
        description: 'API access for apps and integrations, and the developer documentation.',
        sections: [
            { title: 'API documentation', href: '/admin/api-docs', description: 'Who can open the API docs (/docs/api) and developer portal (/developers).', permissions: ['manage_settings'] },
        ],
    },
    {
        title: 'Legal & privacy',
        description: 'Public policies and visitor consent.',
        sections: [
            { title: 'Legal pages', href: '/admin/legal', description: 'Privacy, Terms and Cookie Policy drafts, publishing and history.', permissions: ['edit_legal_content', 'publish_legal_content'] },
            { title: 'Consent notices', href: '/admin/consent', description: 'Cookie, NDPR and GDPR notices and visitor preferences.', permissions: ['manage_settings', 'edit_legal_content'] },
        ],
    },
];

/**
 * Every permission that opens at least one admin page.
 */
export const adminPermissions = [...new Set(adminGroupDefinitions.flatMap((group) => group.sections.flatMap((section) => section.permissions)))];

/**
 * The groups and pages the current user may open. Groups with no allowed pages are left out.
 */
export function adminNavigation(can: (permission: string) => boolean): AdminGroup[] {
    return adminGroupDefinitions
        .map(({ title, description, sections }) => ({
            title,
            description,
            sections: sections
                .filter((section) => section.permissions.some(can))
                .map(({ title: sectionTitle, href, description: sectionDescription }) => ({ title: sectionTitle, href, description: sectionDescription })),
        }))
        .filter((group) => group.sections.length > 0);
}
