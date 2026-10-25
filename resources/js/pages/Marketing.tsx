import { Head } from '@inertiajs/react';
import PublicLayout from '../components/PublicLayout';

const features = [
    ['Security with behavioural intelligence.', 'Sign-in, two-factor authentication, roles and permissions, backed by Aegis: Markov models for behavioural risk and an optional AI analyst for incident explanations.', 'Markov models · AI analyst · Aegis'],
    ['More product. Less plumbing.', 'Teams, an admin area, payments, email and SMS integrations. Connect your providers and focus on what makes your product different.', 'Teams · Payments · Notifications'],
    ['Room for your next chapter.', 'A versioned API, queued jobs and familiar Laravel conventions. Extend the foundation as your workload and ambitions grow.', 'API v1 · Queues · Extensible'],
    ['An experience that feels complete.', 'A React interface, configurable themes, a Help Center, forms and campaign pages. Give your customers more than a login screen.', 'Inertia + React · Themes · Help Center'],
];
const questions = [
    ['How do the Markov models and AI work together?', 'Aegis uses trained Markov baselines to spot unusual event sequences and a second Markov chain to track account risk over time. When enabled and configured, its AI analyst reviews redacted incident evidence, explains the findings and suggests next steps. The security engine and your thresholds control automated responses; AI analysis is advisory.'],
    ['Is Ìpìlẹ̀ really free?', 'Yes. SaltinGStein Ltd. is sharing the boilerplate freely as a contribution to the developer community. Get the source on GitHub. Your own hosting and any paid services you connect are separate costs.'],
    ['What can I build with it?', 'Start a SaaS product, a customer portal, a team workspace or a business application. Keep the foundation, add your own domain and make the experience yours.'],
    ['Is it only for Nigerian developers?', 'Everyone is welcome. Ìpìlẹ̀ has Nigerian roots and a global outlook. The name is Yorùbá for foundation: a place to start something that can go far.'],
    ['Can I extend it and host it myself?', 'Yes. You work with a Laravel application and a React interface in your own repository, on your own infrastructure. See the repository for the licence, setup steps and deployment requirements.'],
    ['Is this page built with Ìpìlẹ̀ too?', 'It is. This page runs inside Ìpìlẹ̀, using its public page layout and permission-protected admin settings. The foundation is showing what it can do.'],
];
const focus = 'focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-current';

function GetButton({ url, light = false }: { url: string; light?: boolean }) {
    return <a href={url} className={`inline-flex items-center justify-center gap-3 rounded-full px-6 py-3.5 text-sm font-semibold transition hover:-translate-y-0.5 motion-reduce:transform-none ${light ? 'bg-[#dbf28b] text-[#113d30] hover:bg-[#e9f8bd]' : 'bg-[#145440] text-[#fffdf5] hover:bg-[#0d3e2e]'} ${focus}`}><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="size-5"><path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-15-2 18" /></svg>Get Ìpìlẹ̀ — it’s free <span aria-hidden="true">↗</span></a>;
}

function FoundationGraphic() {
    return (
        <div className="relative mx-auto w-full max-w-lg lg:pt-8">
            <svg aria-hidden="true" viewBox="0 0 460 390" className="absolute -top-12 right-0 h-96 w-full"><circle cx="330" cy="120" r="100" fill="#e6b166" /><g fill="none" stroke="#145440" opacity=".25">{[0, 1, 2, 3, 4].map((i) => <path key={i} d={`M${35 + i * 22} 0v360m-10-360 10 16 10-16m-20 48 10 16 10-16m-20 48 10 16 10-16`} />)}</g></svg>
            <div className="relative rotate-[-2deg] rounded-2xl border border-[#2d624c] bg-[#123f32] p-6 text-[#f8f5e9] shadow-[12px_18px_0_#d9ddc8] sm:p-8">
                <div className="flex items-center justify-between border-b border-[#49705e] pb-5 font-mono text-xs text-[#c2d2c4]"><span>THE ÌPÌLẸ̀ FOUNDATION</span><span aria-hidden="true">● ● ●</span></div>
                <div className="py-8"><div className="flex items-center justify-between gap-4"><p className="font-serif text-4xl leading-tight sm:text-5xl">Your next<br /><em className="text-[#dbf28b]">big thing.</em></p><span aria-hidden="true" className="text-6xl text-[#dbf28b]">✳</span></div><p className="mt-4 text-sm text-[#c2d2c4]">Your idea. Your code. A head start.</p></div>
                <div className="grid grid-cols-2 gap-2 text-xs sm:text-sm">{['Auth & security', 'Teams & roles', 'Payments & SMS', 'Admin & settings', 'API & queues', 'Your own features ↗'].map((label, i) => <div key={label} className={`rounded-lg border p-3 ${i === 5 ? 'border-[#dbf28b] bg-[#dbf28b] font-semibold text-[#123f32]' : 'border-[#49705e] bg-[#1e4b3c]'}`}>{label}</div>)}</div>
                <div className="mt-6 flex flex-wrap justify-between gap-2 border-t border-[#49705e] pt-5 font-mono text-[10px] tracking-wider text-[#c2d2c4]"><span>LARAVEL + INERTIA + REACT</span><span>BUILT TO BUILD ON</span></div>
            </div>
        </div>
    );
}

export default function Marketing({ repositoryUrl }: { repositoryUrl: string }) {
    return (
        <PublicLayout title="Ìpìlẹ̀ — A free foundation for your next big idea" className="ipile-marketing"
            pageNavigation={[
                { label: 'The foundation', href: '#foundation' },
                { label: 'Markov + AI', href: '#aegis' },
                { label: 'Why free?', href: '#community' },
                { label: 'FAQs', href: '#questions' },
                { label: 'GitHub ↗', href: repositoryUrl },
            ]}>
            <Head><meta name="description" content="A free Laravel, Inertia and React boilerplate from SaltinGStein Ltd. Markov-powered security, optional AI incident analysis, teams, payments and more." /><meta property="og:title" content="Ìpìlẹ̀ — Big ideas deserve a solid foundation." /><meta property="og:description" content="A free Laravel + React starting point. Built in Nigeria. Shared with the world by SaltinGStein Ltd." /></Head>
            <main id="main-content" className="overflow-hidden">
                <section className="relative mx-auto grid max-w-6xl items-center gap-16 px-6 pb-20 pt-16 md:pb-28 md:pt-24 lg:grid-cols-[1.1fr_1fr] lg:gap-12">
                    <div className="relative z-10"><h1 className="max-w-xl font-serif text-6xl leading-[1.04] tracking-[-.045em] text-[#143f31] sm:text-7xl lg:text-[5.3rem]">Big ideas deserve a <em className="font-normal text-[#60804b]">solid foundation.</em></h1><p className="mt-7 max-w-md text-lg leading-8 text-[#526455]">Meet <strong className="text-[#143f31]">Ìpìlẹ̀</strong>. The free Laravel + React boilerplate with Markov-powered security and optional AI incident analysis. A solid start for your next big idea.</p><div className="mt-9 flex flex-wrap items-center gap-5"><GetButton url={repositoryUrl} /><a href="#foundation" className={`text-sm font-semibold text-[#143f31] ${focus}`}>Explore the toolkit ↓</a></div><p className="mt-5 text-xs text-[#526455]">Free to get started. Yours to extend. No checkout required.</p></div>
                    <FoundationGraphic />
                </section>
                <div className="border-y border-[#d7d9c9] bg-[#eceede]"><div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-5 px-6 py-6 text-sm font-semibold text-[#345b49]"><span className="text-xs font-normal uppercase tracking-widest">A familiar stack.<br />A stronger starting point.</span>{['Laravel', 'Inertia', 'React', 'TypeScript', 'Tailwind CSS'].map((name) => <span key={name}>{name}</span>)}</div></div>
                <section id="aegis" aria-labelledby="aegis-heading" className="border-b border-[#d7d9c9] bg-[#eceede]">
                    <div className="mx-auto max-w-6xl px-6 py-16 md:py-20">
                        <div className="grid gap-6 md:grid-cols-[1.1fr_1fr] md:items-end">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[.18em] text-[#60754f]">Meet Aegis · Behavioural security built in</p>
                                <h2 id="aegis-heading" className="mt-4 font-serif text-4xl tracking-tight text-[#143f31] sm:text-5xl">Markov intelligence.<br /><em className="font-normal text-[#60804b]">AI clarity.</em></h2>
                            </div>
                            <p className="max-w-md leading-7 text-[#526455]">Go beyond isolated alerts. Aegis combines Markov models that recognise unusual behaviour and track evolving risk with an optional AI analyst that turns incident evidence into explanations your team can act on.</p>
                        </div>
                        <ol className="mt-10 grid gap-4 md:grid-cols-3">
                            {[
                                ['01 / Recognise the pattern', 'Markov sequence detection', 'Trained baselines compare event sequences with patterns across the platform, a user’s role and their own history, helping surface unusual activity.'],
                                ['02 / Follow the risk', 'Markov risk posture', 'A second chain tracks account risk from normal to critical. Repeated risky events can build up; quiet periods let the risk fade.'],
                                ['03 / Understand the evidence', 'AI-assisted incident analysis', 'When enabled, the AI analyst reviews redacted evidence and returns a verdict, an explanation and suggested next steps for an administrator.'],
                            ].map(([step, title, text]) => (
                                <li key={step} className="rounded-xl border border-[#cdd3bd] bg-[#f8f5e9] p-6">
                                    <p className="font-mono text-xs text-[#60754f]">{step}</p>
                                    <h3 className="mt-4 text-lg font-semibold text-[#143f31]">{title}</h3>
                                    <p className="mt-3 text-sm leading-7 text-[#526455]">{text}</p>
                                </li>
                            ))}
                        </ol>
                        <div className="mt-6 flex flex-col justify-between gap-3 border-t border-[#cdd3bd] pt-5 text-sm leading-6 text-[#345b49] md:flex-row md:gap-10">
                            <p><strong>Your rules stay in control.</strong> The security engine and your configured thresholds govern automated responses. AI provides advice for human review.</p>
                            <p className="md:max-w-xs md:shrink-0">Markov sequence detection needs trained event history. The optional AI analyst needs a configured provider.</p>
                        </div>
                    </div>
                </section>
                <section id="foundation" className="mx-auto max-w-6xl scroll-mt-8 px-6 py-20 md:py-28">
                    <div className="grid gap-6 md:grid-cols-2 md:items-end"><div><p className="text-xs font-semibold uppercase tracking-[.18em] text-[#60754f]">The groundwork, handled.</p><h2 className="mt-4 font-serif text-4xl tracking-tight text-[#143f31] sm:text-5xl">Start where the<br />interesting work begins.</h2></div><p className="max-w-md leading-7 text-[#526455]">You have a product to build. Ìpìlẹ̀ brings the everyday essentials together in one modern, extensible foundation. Keep your focus on the idea only you can bring to life.</p></div>
                    <div className="mt-12 grid gap-x-10 md:grid-cols-2">{features.map(([title, text, tags], i) => <article key={title} className="border-t border-[#cdd3bd] py-8"><span className="font-mono text-xs text-[#60754f]">/0{i + 1}</span><h3 className="mt-4 text-xl font-semibold tracking-tight text-[#143f31]">{title}</h3><p className="mt-3 max-w-md leading-7 text-[#526455]">{text}</p><p className="mt-5 text-xs font-medium text-[#345b49]">{tags}</p></article>)}</div>
                </section>
                <section id="community" className="scroll-mt-8 bg-[#143f31] text-[#f8f5e9]">
                    <div className="mx-auto grid max-w-6xl gap-10 px-6 py-20 md:grid-cols-[1.35fr_1fr] md:gap-20 md:py-24">
                        <div><p className="text-xs font-semibold uppercase tracking-[.18em] text-[#dbf28b]">From our community, for our community.</p><h2 className="mt-5 font-serif text-4xl leading-tight tracking-tight sm:text-5xl">Good foundations<br />should open doors.</h2><p className="mt-6 max-w-lg leading-8 text-[#d1dece]">In Yorùbá, <em>ìpìlẹ̀</em> means foundation. We believe a developer’s ambition should go further than their starting budget. So we’re giving our starting point back to the community.</p><p className="mt-5 max-w-lg leading-8 text-[#d1dece]">Built with care in Nigeria by <strong className="text-[#f8f5e9]">SaltinGStein Ltd.</strong>, for makers everywhere. Take it, learn from it, build on it, and help the next person start stronger.</p><p className="mt-8 font-serif text-xl italic text-[#dbf28b]">Na our contribution be this.</p></div>
                        <div className="flex flex-col justify-center border-l border-[#54705a] pl-8 md:pl-12"><p className="text-sm text-[#d1dece]">The foundation</p><p className="mt-2 font-serif text-8xl tracking-tighter">₦0<span className="ml-2 text-lg tracking-normal text-[#dbf28b]">/ free</span></p><p className="mt-4 max-w-xs text-sm leading-7 text-[#d1dece]">The source is freely available. Bring your idea and your own hosting. Check the repository for licence and setup details.</p><ul className="my-8 flex flex-col gap-3 text-sm">{['Full source on GitHub', 'A foundation you can make your own', 'No boilerplate purchase required'].map((item) => <li key={item} className="flex gap-3"><span aria-hidden="true" className="text-[#dbf28b]">✓</span>{item}</li>)}</ul><div><GetButton url={repositoryUrl} light /></div></div>
                    </div>
                </section>
                <section className="mx-auto grid max-w-6xl gap-10 px-6 py-20 md:grid-cols-[.8fr_1.2fr] md:py-28"><div><p className="text-xs font-semibold uppercase tracking-[.18em] text-[#60754f]">From foundation to first launch.</p><h2 className="mt-4 font-serif text-4xl tracking-tight text-[#143f31]">Make it yours.<br />Then make it happen.</h2></div><ol className="flex flex-col gap-7">{[['Get the source', 'Open the GitHub repository and follow the setup guide to get your local application running.'], ['Build your difference', 'Choose your providers, set your brand and add the features your customers actually need.'], ['Launch and grow', 'Deploy on your infrastructure. Configure your workers and services, then keep improving your product.']].map(([title, text], i) => <li key={title} className="flex gap-5 border-b border-[#d7d9c9] pb-7"><span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#e4e9d3] font-mono text-sm text-[#143f31]">0{i + 1}</span><div><h3 className="font-semibold text-[#143f31]">{title}</h3><p className="mt-2 text-sm leading-7 text-[#526455]">{text}</p></div></li>)}</ol></section>
                <section id="questions" className="mx-auto max-w-6xl scroll-mt-8 border-t border-[#d7d9c9] px-6 py-16 md:py-20"><div className="grid gap-10 md:grid-cols-[.8fr_1.2fr]"><div><p className="text-xs font-semibold uppercase tracking-[.18em] text-[#60754f]">A few things to know.</p><h2 className="mt-4 font-serif text-4xl tracking-tight text-[#143f31]">Good questions.<br />Straight answers.</h2></div><div>{questions.map(([question, answer]) => <details key={question} className="group border-b border-[#d7d9c9] py-5 first:pt-0"><summary className={`flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-[#143f31] [&::-webkit-details-marker]:hidden ${focus}`}>{question}<span aria-hidden="true" className="text-xl font-normal group-open:rotate-45">+</span></summary><p className="mt-4 text-sm leading-7 text-[#526455]">{answer}</p></details>)}</div></div></section>
                <section className="mx-auto max-w-6xl px-6 pb-16 pt-6"><div className="flex flex-col items-start justify-between gap-8 rounded-2xl bg-[#e4e9d3] p-8 sm:p-12 md:flex-row md:items-center"><div><p className="text-xs font-semibold uppercase tracking-[.18em] text-[#526455]">Your next chapter starts here.</p><h2 className="mt-3 font-serif text-4xl tracking-tight text-[#143f31]">Oya, let’s build.</h2><p className="mt-3 text-sm text-[#526455]">Built on Ìpìlẹ̀. Just like this very page.</p></div><GetButton url={repositoryUrl} /></div></section>
            </main>
        </PublicLayout>
    );
}
