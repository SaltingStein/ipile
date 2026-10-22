import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { parseVideoUrl } from '../media/videoEmbed';
import { accentStyle } from './types';
import type { CampaignButton, CampaignFormData, CampaignPageData, CampaignSection } from './types';

/**
 * A campaign page in one of its three templates. The public page and the builder's live preview both use it,
 * so what marketers see while editing is what visitors get.
 *
 * - **Spotlight**: full-width banner (or a large video under the headline), centred text, wide sections.
 * - **Split**: headline and buttons on the left; the form (or the picture or video) on the right.
 * - **Story**: a narrow editorial column with the picture under the headline.
 */
interface CampaignPageViewProps {
    page: CampaignPageData;
    /** Renders the page's form, e.g. a working form on the public page and a stand-in in the preview. */
    renderForm: (form: CampaignFormData) => ReactNode;
    /** Called when a visitor chooses one of the page's buttons. */
    onButtonClick?: () => void;
}

type Template = CampaignPageData['template'];

export default function CampaignPageView({ page, renderForm, onButtonClick }: CampaignPageViewProps) {
    const formSection = page.sections.find((section) => section.type === 'form');
    // In Split, the form sits beside the headline instead of in the list of sections.
    const formInHero = page.template === 'split' && formSection !== undefined && page.form !== null;
    const sections = formInHero ? page.sections.filter((section) => section.type !== 'form') : page.sections;
    const width = page.template === 'story' ? 'max-w-3xl' : 'max-w-6xl';

    const formBlock = (heading: string | null, text: string | null) => (
        <div id="signup" className="flex scroll-mt-6 flex-col gap-4">
            {(heading || text) && (
                <div className="flex flex-col gap-2">
                    {heading && <h2 className="text-2xl font-bold text-slate-50">{heading}</h2>}
                    {text && <p className="whitespace-pre-line text-slate-300">{text}</p>}
                </div>
            )}
            {page.has_ended
                ? <Ended message={page.ended_message} />
                : page.form ? renderForm(page.form) : <p className="text-sm text-slate-400">Choose a form for this section.</p>}
        </div>
    );

    return (
        <div style={accentStyle(page.accent)} className="flex flex-col">
            <Hero page={page} onButtonClick={onButtonClick}
                aside={formInHero && formSection?.type === 'form' ? formBlock(formSection.heading, formSection.text) : null} />

            {page.has_ended && !formInHero && (
                <div className={`mx-auto w-full ${width} px-6 pt-10`}><Ended message={page.ended_message} /></div>
            )}

            <div id="details" className={`mx-auto flex w-full scroll-mt-6 ${width} flex-col ${page.template === 'story' ? 'gap-12 py-12' : 'gap-16 py-16'} px-6`}>
                {sections.map((section) => (
                    <div key={section.id} id={section.type === 'form' ? undefined : `section-${section.id}`} className="scroll-mt-6">
                        {section.type === 'form'
                            ? <div className={`mx-auto w-full ${page.template === 'story' ? '' : 'max-w-2xl'}`}>{formBlock(section.heading, section.text)}</div>
                            : <Section section={section} template={page.template} hasEnded={page.has_ended} onButtonClick={onButtonClick} />}
                    </div>
                ))}
            </div>
        </div>
    );
}

function Hero({ page, aside, onButtonClick }: { page: CampaignPageData; aside: ReactNode; onButtonClick?: () => void }) {
    const { hero, template, has_ended: hasEnded } = page;
    const video = hero.media === 'video' ? parseVideoUrl(hero.video_url) : null;
    const image = hero.media === 'image' ? hero.image_url : null;
    const buttons = hasEnded ? [] : hero.buttons;
    const copy = (align: 'center' | 'left', large: boolean) => (
        <div className={`flex flex-col gap-5 ${align === 'center' ? 'items-center text-center' : ''}`}>
            {hero.eyebrow && <p className="text-sm font-semibold uppercase tracking-[0.2em] text-[var(--accent)]">{hero.eyebrow}</p>}
            <h1 className={`font-bold tracking-tight ${large ? 'text-4xl sm:text-6xl' : 'text-4xl sm:text-5xl'} ${template === 'story' ? 'font-serif' : ''} text-balance`}>
                {hero.headline}
            </h1>
            {hero.text && <p className={`max-w-2xl whitespace-pre-line text-lg leading-relaxed ${image && template === 'spotlight' ? 'text-slate-100' : 'text-slate-300'}`}>{hero.text}</p>}
            {buttons.length > 0 && <Buttons buttons={buttons} center={align === 'center'} onClick={onButtonClick} />}
        </div>
    );

    if (template === 'split') {
        return (
            <section className="border-b border-slate-800 bg-gradient-to-br from-slate-900 via-slate-950 to-slate-950">
                <div className="mx-auto grid w-full max-w-6xl items-center gap-10 px-6 py-14 lg:grid-cols-2 lg:py-20">
                    <div className="text-slate-50">{copy('left', false)}</div>
                    <div>
                        {aside ? (
                            <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl shadow-black/30">{aside}</div>
                        ) : video ? (
                            <VideoFrame embedUrl={video.embedUrl} title={hero.headline} />
                        ) : image ? (
                            <img src={image} alt={hero.image_alt ?? ''} className="aspect-[4/3] w-full rounded-2xl object-cover shadow-2xl shadow-black/30" />
                        ) : (
                            <div aria-hidden="true" className="aspect-[4/3] rounded-2xl bg-[radial-gradient(circle_at_30%_30%,var(--accent),transparent_60%)] opacity-60" />
                        )}
                    </div>
                </div>
            </section>
        );
    }

    if (template === 'story') {
        return (
            <section className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 pt-14 text-slate-50">
                {copy('left', false)}
                {video && <VideoFrame embedUrl={video.embedUrl} title={hero.headline} />}
                {image && <img src={image} alt={hero.image_alt ?? ''} className="aspect-[16/9] w-full rounded-2xl object-cover" />}
                <hr className="border-slate-800" />
            </section>
        );
    }

    return (
        <section className="relative isolate overflow-hidden border-b border-slate-800">
            {image && <img src={image} alt={hero.image_alt ?? ''} className="absolute inset-0 -z-20 size-full object-cover" />}
            <div aria-hidden="true" className={`absolute inset-0 -z-10 ${image
                ? 'bg-gradient-to-t from-slate-950 via-slate-950/70 to-slate-950/30'
                : 'bg-[radial-gradient(ellipse_at_top,color-mix(in_srgb,var(--accent)_35%,transparent),transparent_65%)]'}`} />
            <div className={`mx-auto flex w-full max-w-5xl flex-col gap-10 px-6 ${image ? 'py-28 text-white sm:py-36' : 'py-20 text-slate-50 sm:py-28'}`}>
                {copy('center', true)}
                {video && <VideoFrame embedUrl={video.embedUrl} title={hero.headline} />}
            </div>
        </section>
    );
}

function Section({ section, template, hasEnded, onButtonClick }: { section: CampaignSection; template: Template; hasEnded: boolean; onButtonClick?: () => void }) {
    const centred = template === 'spotlight';
    const heading = (text: string | null) => text && (
        <h2 className={`text-3xl font-bold tracking-tight text-slate-50 ${template === 'story' ? 'font-serif' : ''} ${centred ? 'text-center' : ''}`}>{text}</h2>
    );

    switch (section.type) {
        case 'text':
            return (
                <div className={`flex flex-col gap-4 ${centred ? 'mx-auto max-w-3xl' : ''}`}>
                    {heading(section.heading)}
                    {section.body && <p className="whitespace-pre-line text-lg leading-8 text-slate-300">{section.body}</p>}
                </div>
            );
        case 'features':
            return (
                <div className="flex flex-col gap-8">
                    {heading(section.heading)}
                    <ul className={`grid gap-5 ${template === 'story' ? 'sm:grid-cols-2' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
                        {section.items.map((item, index) => (
                            <li key={index} className="flex flex-col gap-2 rounded-2xl border border-slate-800 bg-slate-900/70 p-6">
                                <span aria-hidden="true" className="mb-1 h-1 w-10 rounded-full bg-[var(--accent)]" />
                                <h3 className="text-lg font-semibold text-slate-50">{item.title}</h3>
                                {item.text && <p className="text-slate-400">{item.text}</p>}
                            </li>
                        ))}
                    </ul>
                </div>
            );
        case 'image':
            return section.image_url ? (
                <figure className="flex flex-col gap-3">
                    <img src={section.image_url} alt={section.alt ?? ''} loading="lazy" className="w-full rounded-2xl object-cover" />
                    {section.caption && <figcaption className="text-center text-sm text-slate-400">{section.caption}</figcaption>}
                </figure>
            ) : null;
        case 'video': {
            const video = parseVideoUrl(section.video_url);

            return video ? (
                <figure className="flex flex-col gap-3">
                    <VideoFrame embedUrl={video.embedUrl} title={section.caption ?? 'Video'} />
                    {section.caption && <figcaption className="text-center text-sm text-slate-400">{section.caption}</figcaption>}
                </figure>
            ) : null;
        }
        case 'cta':
            return hasEnded ? null : (
                <div className={`flex flex-col gap-5 rounded-3xl border border-slate-800 p-8 sm:p-12 ${centred ? 'items-center text-center' : ''} bg-[linear-gradient(135deg,color-mix(in_srgb,var(--accent)_22%,transparent),transparent_70%)]`}>
                    <h2 className={`text-3xl font-bold text-slate-50 ${template === 'story' ? 'font-serif' : ''}`}>{section.heading}</h2>
                    {section.text && <p className="max-w-2xl whitespace-pre-line text-lg text-slate-300">{section.text}</p>}
                    <Buttons buttons={section.buttons} center={centred} onClick={onButtonClick} />
                </div>
            );
        case 'countdown':
            return <Countdown heading={section.heading} target={section.target} centred={template !== 'story'} />;
        case 'faq':
            return (
                <div className={`flex flex-col gap-6 ${centred ? 'mx-auto w-full max-w-3xl' : ''}`}>
                    {heading(section.heading)}
                    <div className="flex flex-col divide-y divide-slate-800 rounded-2xl border border-slate-800">
                        {section.items.map((item, index) => (
                            <details key={index} className="group px-5 py-4">
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-slate-100">
                                    {item.question}
                                    <span aria-hidden="true" className="text-[var(--accent)] transition group-open:rotate-45">+</span>
                                </summary>
                                <p className="mt-3 whitespace-pre-line text-slate-400">{item.answer}</p>
                            </details>
                        ))}
                    </div>
                </div>
            );
        case 'quote':
            return (
                <figure className={`flex flex-col gap-4 ${centred ? 'mx-auto max-w-3xl items-center text-center' : 'border-l-4 border-[var(--accent)] pl-6'}`}>
                    <blockquote className={`text-2xl leading-relaxed text-slate-100 ${template === 'story' ? 'font-serif italic' : 'font-medium'}`}>“{section.quote}”</blockquote>
                    {(section.name || section.role) && (
                        <figcaption className="text-sm text-slate-400">
                            {section.name && <span className="font-semibold text-slate-200">{section.name}</span>}
                            {section.name && section.role && ', '}
                            {section.role}
                        </figcaption>
                    )}
                </figure>
            );
        case 'stats':
            return (
                <dl className={`grid gap-6 ${section.items.length > 2 ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-2'} ${centred ? 'text-center' : ''}`}>
                    {section.items.map((item, index) => (
                        <div key={index} className="flex flex-col-reverse gap-1">
                            <dt className="text-sm text-slate-400">{item.label}</dt>
                            <dd className="text-4xl font-bold tracking-tight text-[var(--accent)]">{item.value}</dd>
                        </div>
                    ))}
                </dl>
            );
        default:
            return null;
    }
}

function Buttons({ buttons, center, onClick }: { buttons: CampaignButton[]; center: boolean; onClick?: () => void }) {
    return (
        <div className={`flex flex-wrap gap-3 ${center ? 'justify-center' : ''}`}>
            {buttons.map((button, index) => {
                const external = /^https?:\/\//i.test(button.url);

                return (
                    <a key={index} href={button.url} onClick={onClick} {...(external ? { rel: 'noopener' } : {})}
                        className={`rounded-full px-6 py-3 font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)] ${button.style === 'primary'
                            ? 'bg-[var(--accent)] text-slate-950 hover:brightness-110'
                            : 'border border-slate-600 text-current hover:border-slate-400'}`}>
                        {button.label}
                    </a>
                );
            })}
        </div>
    );
}

function VideoFrame({ embedUrl, title }: { embedUrl: string; title: string }) {
    return (
        <div className="video-embed !my-0 shadow-2xl shadow-black/40">
            <iframe src={embedUrl} title={title} loading="lazy" referrerPolicy="strict-origin-when-cross-origin" allowFullScreen
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" />
        </div>
    );
}

function Ended({ message }: { message: string }) {
    return (
        <div role="status" className="rounded-2xl border border-slate-700 bg-slate-900 p-6 text-center text-slate-200">
            <p className="whitespace-pre-line">{message}</p>
        </div>
    );
}

/**
 * Days, hours, minutes and seconds until the target. Updates every second; screen readers hear the date
 * instead of a ticking number.
 */
function Countdown({ heading, target, centred }: { heading: string | null; target: string | null; centred: boolean }) {
    const end = target ? new Date(target) : null;
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    if (!end || Number.isNaN(end.getTime())) {
        return null;
    }

    const remaining = Math.max(0, end.getTime() - now);
    const parts = [
        { label: 'Days', value: Math.floor(remaining / 86_400_000) },
        { label: 'Hours', value: Math.floor(remaining / 3_600_000) % 24 },
        { label: 'Minutes', value: Math.floor(remaining / 60_000) % 60 },
        { label: 'Seconds', value: Math.floor(remaining / 1000) % 60 },
    ];
    const date = end.toLocaleString(undefined, { day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit' });

    return (
        <div className={`flex flex-col gap-5 ${centred ? 'items-center text-center' : ''}`}>
            {heading && <h2 className="text-2xl font-bold text-slate-50">{heading}</h2>}
            {remaining === 0 ? (
                <p className="text-lg text-slate-300">Time is up: <time dateTime={target ?? undefined}>{date}</time>.</p>
            ) : (
                <>
                    <p className="sr-only">Until <time dateTime={target ?? undefined}>{date}</time></p>
                    <ol aria-hidden="true" className="flex gap-3">
                        {parts.map((part) => (
                            <li key={part.label} className="flex min-w-20 flex-col items-center rounded-2xl border border-slate-800 bg-slate-900 px-4 py-3">
                                <span className="text-3xl font-bold tabular-nums text-[var(--accent)]">{String(part.value).padStart(2, '0')}</span>
                                <span className="text-xs uppercase tracking-wide text-slate-400">{part.label}</span>
                            </li>
                        ))}
                    </ol>
                </>
            )}
        </div>
    );
}

