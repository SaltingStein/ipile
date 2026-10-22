import { Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import FormList from '../../components/forms/FormList';
import NewFormPicker from '../../components/forms/NewFormPicker';
import EmptyState from '../../components/ui/EmptyState';
import Panel from '../../components/ui/Panel';
import type { FormSummary, FormTemplateOption } from '../../types';

interface FormsIndexProps {
    team: { id: number; name: string } | null;
    canManage: boolean;
    forms: FormSummary[];
    templates: FormTemplateOption[];
}

export default function FormsIndex({ team, canManage, forms, templates }: FormsIndexProps) {
    return (
        <AppLayout title="Forms">
            <div className="flex flex-col gap-1">
                <h1 className="text-2xl font-bold">Forms</h1>
                <p className="text-sm text-slate-400">
                    Build registration, sign-up and survey forms by dragging in fields, then share the link.
                </p>
            </div>

            {team === null ? (
                <EmptyState
                    title="Choose a team first"
                    description="Forms belong to a team, so everyone running the event can work on them. Create a team or switch to one from the menu at the top."
                    action={<Link href="/teams" className="font-semibold text-emerald-400 hover:text-emerald-300">Go to Teams</Link>}
                />
            ) : (
                <>
                    <Panel title={`${team.name} forms`}>
                        <FormList forms={forms} canManage={canManage}
                            emptyDescription={canManage ? 'Create your first form below.' : 'The team owner or an admin can create forms.'} />
                    </Panel>
                    {canManage && (
                        <Panel title="Create a form" description="Start from a template and change anything in the builder.">
                            <NewFormPicker templates={templates} createUrl="/forms" />
                        </Panel>
                    )}
                </>
            )}
        </AppLayout>
    );
}
