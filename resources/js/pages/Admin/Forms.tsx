import AdminLayout from '../../components/AdminLayout';
import FormList from '../../components/forms/FormList';
import NewFormPicker from '../../components/forms/NewFormPicker';
import Panel from '../../components/ui/Panel';
import type { FormSummary, FormTemplateOption } from '../../types';

interface AdminFormsProps {
    forms: FormSummary[];
    templates: FormTemplateOption[];
}

export default function AdminForms({ forms, templates }: AdminFormsProps) {
    return (
        <AdminLayout title="Forms">
            <Panel title="Site forms"
                description="Forms that belong to the whole site, such as a contact form or waitlist. “Sign-up questions” adds your own fields to the sign-up page. Teams build their own forms under Forms in the workspace.">
                <FormList forms={forms} canManage emptyDescription="Create your first site form below." />
            </Panel>
            <Panel title="Create a site form" description="Start from a template and change anything in the builder.">
                <NewFormPicker templates={templates} createUrl="/admin/forms" />
            </Panel>
        </AdminLayout>
    );
}
