import type { CSSProperties } from 'react';
import type { FormFieldDefinition } from '../../types';

/**
 * Campaign page content, as saved by App\Services\Campaigns\CampaignContent.
 */
export type CampaignTemplateValue = 'spotlight' | 'split' | 'story';

export type CampaignAccent = 'emerald' | 'sky' | 'violet' | 'amber' | 'rose' | 'slate';

export interface CampaignButton {
    label: string;
    url: string;
    style: 'primary' | 'secondary';
}

export interface CampaignHero {
    eyebrow: string | null;
    headline: string;
    text: string | null;
    buttons: CampaignButton[];
    media: 'none' | 'image' | 'video';
    image: string | null;
    image_alt: string | null;
    video_url: string | null;
    /** Added for display: the address of `image`. */
    image_url?: string | null;
}

interface SectionBase {
    id: string;
}

export type CampaignSection = SectionBase & (
    | { type: 'text'; heading: string | null; body: string | null }
    | { type: 'features'; heading: string | null; items: { title: string | null; text: string | null }[] }
    | { type: 'image'; image: string | null; alt: string | null; caption: string | null; image_url?: string | null }
    | { type: 'video'; video_url: string | null; caption: string | null }
    | { type: 'cta'; heading: string | null; text: string | null; buttons: CampaignButton[] }
    | { type: 'form'; form_id: number | null; heading: string | null; text: string | null }
    | { type: 'countdown'; heading: string | null; target: string | null }
    | { type: 'faq'; heading: string | null; items: { question: string | null; answer: string | null }[] }
    | { type: 'quote'; quote: string | null; name: string | null; role: string | null }
    | { type: 'stats'; items: { value: string | null; label: string | null }[] }
);

export type CampaignSectionType = CampaignSection['type'];

export interface CampaignFormData {
    id: number;
    title: string;
    fields: FormFieldDefinition[];
    submit_label: string;
    closed_reason: string | null;
    places_left: number | null;
    success_message: string;
}

/**
 * Everything the page needs to render, from App\Services\Campaigns\CampaignPresenter.
 */
export interface CampaignPageData {
    title: string;
    slug: string;
    template: CampaignTemplateValue;
    accent: CampaignAccent;
    meta_description: string | null;
    hero: CampaignHero;
    sections: CampaignSection[];
    ends_at: string | null;
    has_ended: boolean;
    ended_message: string;
    form: CampaignFormData | null;
    cover_url: string | null;
}

export const accentColours: Record<CampaignAccent, { label: string; colour: string }> = {
    emerald: { label: 'Emerald', colour: '#10b981' },
    sky: { label: 'Sky', colour: '#0ea5e9' },
    violet: { label: 'Violet', colour: '#8b5cf6' },
    amber: { label: 'Amber', colour: '#f59e0b' },
    rose: { label: 'Rose', colour: '#f43f5e' },
    slate: { label: 'Slate', colour: '#94a3b8' },
};

/**
 * Sets `--accent` for the page; buttons and highlights use it.
 */
export function accentStyle(accent: CampaignAccent): CSSProperties {
    return { '--accent': accentColours[accent]?.colour ?? accentColours.emerald.colour } as CSSProperties;
}

export const sectionLabels: Record<CampaignSectionType, { label: string; description: string; icon: string }> = {
    text: { label: 'Text', description: 'A heading and paragraphs', icon: '¶' },
    features: { label: 'Features', description: 'Up to 6 benefits in a grid', icon: '▦' },
    image: { label: 'Picture', description: 'A picture with a caption', icon: '🖼' },
    video: { label: 'Video', description: 'A YouTube or Vimeo video', icon: '▶' },
    cta: { label: 'Call to action', description: 'A heading with buttons', icon: '➜' },
    form: { label: 'Form', description: 'A sign-up form from Admin → Forms', icon: '✎' },
    countdown: { label: 'Countdown', description: 'Days, hours and minutes to a date', icon: '⏱' },
    faq: { label: 'Questions', description: 'Questions and answers', icon: '?' },
    quote: { label: 'Quote', description: 'A testimonial or quote', icon: '❝' },
    stats: { label: 'Figures', description: 'Up to 4 big numbers', icon: '#' },
};
