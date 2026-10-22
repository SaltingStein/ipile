import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import Panel from '../../components/ui/Panel';
import TextField from '../../components/ui/TextField';

interface AuthenticationSettingsValues {
    registration_enabled: boolean;
    guest_access_enabled: boolean;
    magic_link_enabled: boolean;
    magic_link_expiry_minutes: number;
    trusted_device_days: number;
    two_factor_required_roles: string[];
    api_token_lifetime_days: number;
    api_token_idle_days: number;
}

interface ProviderSettings {
    provider: string;
    label: string;
    is_enabled: boolean;
    client_id: string | null;
    has_client_secret: boolean;
    callback_url: string;
}

interface AuthenticationSettingsProps {
    settings: AuthenticationSettingsValues;
    roles: string[];
    providers: ProviderSettings[];
    signInMethods: string[];
}

const keepScroll = { preserveScroll: true };

export default function AuthenticationSettings({ settings, roles, providers, signInMethods }: AuthenticationSettingsProps) {
    return (
        <AdminLayout title="Sign-in & 2FA">
            <Panel title="Available sign-in methods" description="The saved methods currently offered on the sign-in page. Connected providers appear here only when enabled and fully configured.">
                <ul className="flex list-disc flex-col gap-2 pl-5 text-sm text-slate-200">
                    {signInMethods.map((method) => <li key={method}>{method}</li>)}
                </ul>
                <p className="text-sm text-slate-400">New registrations: {settings.registration_enabled ? 'Open' : 'Closed'}. Guest access: {settings.guest_access_enabled ? 'On' : 'Off'}.</p>
                <p className="text-xs text-slate-400">Closing registration stops new accounts. Existing accounts can still sign in, and their two-factor rules still apply.</p>
            </Panel>
            <GeneralSettingsPanel settings={settings} roles={roles} />
            {providers.map((provider) => (
                <ProviderPanel key={provider.provider} provider={provider} />
            ))}
        </AdminLayout>
    );
}

function GeneralSettingsPanel({ settings, roles }: { settings: AuthenticationSettingsValues; roles: string[] }) {
    const { data, setData, put, processing, errors } = useForm({
        registration_enabled: settings.registration_enabled,
        guest_access_enabled: settings.guest_access_enabled,
        magic_link_enabled: settings.magic_link_enabled,
        magic_link_expiry_minutes: String(settings.magic_link_expiry_minutes),
        trusted_device_days: String(settings.trusted_device_days),
        two_factor_required_roles: settings.two_factor_required_roles,
        api_token_lifetime_days: String(settings.api_token_lifetime_days),
        api_token_idle_days: String(settings.api_token_idle_days),
    });

    function toggleRole(role: string, isRequired: boolean) {
        setData('two_factor_required_roles', isRequired
            ? [...data.two_factor_required_roles, role]
            : data.two_factor_required_roles.filter((name) => name !== role));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put('/admin/authentication', keepScroll);
    }

    return (
        <Panel title="Sign-in rules" description="Changes apply immediately to every user.">
            <form onSubmit={submit} className="flex max-w-xl flex-col gap-5">
                <Checkbox
                    label="Allow new registrations"
                    name="registration_enabled"
                    description="When off, the register page, the registration API and new sign-ups through connected accounts are closed."
                    checked={data.registration_enabled}
                    onChange={(checked) => setData('registration_enabled', checked)}
                />
                <Checkbox
                    label="Allow guest access"
                    name="guest_access_enabled"
                    description="Show guest quick-start forms on the home page and allow new guest sessions. Turning this off keeps existing guest sessions."
                    checked={data.guest_access_enabled}
                    onChange={(checked) => setData('guest_access_enabled', checked)}
                />
                {errors.guest_access_enabled && <p className="text-sm text-rose-400">{errors.guest_access_enabled}</p>}
                <Checkbox
                    label="Allow sign-in by email link"
                    name="magic_link_enabled"
                    description="People with an existing active account can request a single-use sign-in link. Their two-factor rules still apply."
                    checked={data.magic_link_enabled}
                    onChange={(checked) => setData('magic_link_enabled', checked)}
                />
                {errors.magic_link_enabled && <p className="text-sm text-rose-400">{errors.magic_link_enabled}</p>}
                <TextField label="Sign-in link expiry (minutes)" name="magic_link_expiry_minutes" type="number" min={1} max={60}
                    hint="1–60 minutes; defaults to 15. Applies to new links. Existing links cannot be used while email-link sign-in is off."
                    value={data.magic_link_expiry_minutes} onChange={(value) => setData('magic_link_expiry_minutes', value)} error={errors.magic_link_expiry_minutes} />
                <TextField
                    label="Trusted device period (days)"
                    name="trusted_device_days"
                    type="number"
                    min={0}
                    max={365}
                    hint="How long 'Trust this device' skips the two-factor code. Use 0 to turn the option off."
                    value={data.trusted_device_days}
                    onChange={(value) => setData('trusted_device_days', value)}
                    error={errors.trusted_device_days}
                />
                <TextField
                    label="API token lifetime (days)"
                    name="api_token_lifetime_days"
                    type="number"
                    min={0}
                    max={3650}
                    hint="New API tokens (mobile apps, integrations) stop working this long after they are created. Use 0 for no limit. Existing tokens keep their expiry."
                    value={data.api_token_lifetime_days}
                    onChange={(value) => setData('api_token_lifetime_days', value)}
                    error={errors.api_token_lifetime_days}
                />
                <TextField
                    label="API token idle timeout (days)"
                    name="api_token_idle_days"
                    type="number"
                    min={0}
                    max={3650}
                    hint="A token that is not used for this long stops working, so active app users stay signed in while forgotten devices are cut off. Applies to existing tokens too. Use 0 to turn it off."
                    value={data.api_token_idle_days}
                    onChange={(value) => setData('api_token_idle_days', value)}
                    error={errors.api_token_idle_days}
                />
                <fieldset className="flex flex-col gap-3">
                    <legend className="mb-2 text-sm font-medium text-slate-200">Roles that must use two-factor authentication</legend>
                    {roles.map((role) => (
                        <Checkbox
                            key={role}
                            label={role}
                            name={`two_factor_role_${role}`}
                            checked={data.two_factor_required_roles.includes(role)}
                            onChange={(checked) => toggleRole(role, checked)}
                        />
                    ))}
                    {errors.two_factor_required_roles && <p className="text-sm text-rose-400">{errors.two_factor_required_roles}</p>}
                </fieldset>
                <Button disabled={processing} className="self-start">Save sign-in rules</Button>
            </form>
        </Panel>
    );
}

function ProviderPanel({ provider }: { provider: ProviderSettings }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        is_enabled: provider.is_enabled,
        client_id: provider.client_id ?? '',
        client_secret: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(`/admin/oauth-providers/${provider.provider}`, { ...keepScroll, onSuccess: () => reset('client_secret') });
    }

    return (
        <Panel
            title={`${provider.label} sign-in`}
            description={`Create an OAuth client in the ${provider.label} developer console and paste its credentials here.`}
        >
            <form onSubmit={submit} className="flex max-w-xl flex-col gap-4">
                <TextField
                    label="Authorised redirect URI (copy into the provider console)"
                    name={`${provider.provider}_callback_url`}
                    value={provider.callback_url}
                    onChange={() => undefined}
                    readOnly
                />
                <TextField
                    label="Client ID"
                    name={`${provider.provider}_client_id`}
                    autoComplete="off"
                    value={data.client_id}
                    onChange={(value) => setData('client_id', value)}
                    error={errors.client_id}
                />
                <TextField
                    label="Client secret"
                    name={`${provider.provider}_client_secret`}
                    type="password"
                    autoComplete="new-password"
                    placeholder={provider.has_client_secret ? 'Saved — leave blank to keep it' : ''}
                    hint="Stored encrypted. It is never shown again after saving."
                    value={data.client_secret}
                    onChange={(value) => setData('client_secret', value)}
                    error={errors.client_secret}
                />
                <Checkbox
                    label={`Show "Continue with ${provider.label}" on the sign-in and register pages`}
                    name={`${provider.provider}_is_enabled`}
                    checked={data.is_enabled}
                    onChange={(checked) => setData('is_enabled', checked)}
                />
                <Button disabled={processing} className="self-start">Save {provider.label} settings</Button>
            </form>
        </Panel>
    );
}
