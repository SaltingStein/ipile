import type { CampaignSection, CampaignSectionType } from '../types';

/**
 * A short random id for a new section; it also makes the section's anchor (#section-…).
 */
export function newSectionId(): string {
    return Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
}

/**
 * A new section of a type, with placeholder text to replace.
 */
export function newSection(type: CampaignSectionType, firstFormId: number | null): CampaignSection {
    const id = newSectionId();

    switch (type) {
        case 'text':
            return { id, type, heading: 'A heading', body: 'Write a few short paragraphs here.' };
        case 'features':
            return { id, type, heading: 'Why it is worth it', items: [{ title: 'A benefit', text: 'One line about it.' }, { title: 'Another benefit', text: 'One line about it.' }] };
        case 'image':
            return { id, type, image: null, alt: null, caption: null };
        case 'video':
            return { id, type, video_url: null, caption: null };
        case 'cta':
            return { id, type, heading: 'Ready to join?', text: null, buttons: [{ label: 'Get started', url: '#signup', style: 'primary' }] };
        case 'form':
            return { id, type, form_id: firstFormId, heading: 'Sign up', text: null };
        case 'countdown': {
            const inAWeek = new Date(Date.now() + 7 * 86_400_000);
            inAWeek.setHours(9, 0, 0, 0);

            return { id, type, heading: 'Starts in', target: inAWeek.toISOString() };
        }
        case 'faq':
            return { id, type, heading: 'Questions', items: [{ question: 'A question people ask', answer: 'The answer.' }] };
        case 'quote':
            return { id, type, quote: 'What someone said about it.', name: null, role: null };
        case 'stats':
            return { id, type, items: [{ value: '100+', label: 'Something to count' }, { value: '4.9', label: 'Average rating' }] };
    }
}

/**
 * One line describing a section in the builder's list.
 */
export function sectionSummary(section: CampaignSection): string {
    switch (section.type) {
        case 'text':
        case 'cta':
            return section.heading || (section.type === 'text' ? section.body : section.text) || '';
        case 'features':
        case 'faq':
            return `${section.heading ? `${section.heading} · ` : ''}${section.items.length} ${section.items.length === 1 ? 'item' : 'items'}`;
        case 'image':
            return section.caption || section.alt || (section.image ? 'Picture' : 'No picture yet');
        case 'video':
            return section.caption || section.video_url || 'No video yet';
        case 'form':
            return section.heading || 'Form';
        case 'countdown':
            return section.target ? new Date(section.target).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'No date yet';
        case 'quote':
            return section.name || section.quote || '';
        case 'stats':
            return section.items.map((item) => item.value).filter(Boolean).join(' · ');
    }
}

/**
 * Copy a section with a new id, so it can be edited separately.
 */
export function duplicateSection(section: CampaignSection): CampaignSection {
    return { ...structuredClone(section), id: newSectionId() };
}
