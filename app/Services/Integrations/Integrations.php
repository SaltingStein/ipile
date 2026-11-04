<?php

namespace App\Services\Integrations;

/**
 * Add your own providers to Admin → Integrations from a service provider's boot(), instead of editing
 * {@see IntegrationCatalog}:
 *
 *     Integrations::define(new IntegrationDefinition('acme_mail', IntegrationType::Mail, 'Acme Mail',
 *         'Transactional email through Acme.', [
 *             IntegrationField::secret('api_key', 'API key'),
 *             new IntegrationField('from_address', 'From address', input: 'email'),
 *         ], AcmeMailTester::class, transport: AcmeMailTransport::class));
 *
 * The settings form, encryption and "Test connection" button come with it. An email provider with a
 * `transport` can be chosen in Admin → Integrations → Email delivery (see `MailProviders`).
 *
 * @api
 */
final class Integrations
{
    /**
     * Add a provider. Throws if the key is taken, so a product cannot replace an Ìpìlẹ̀ provider by accident.
     */
    public static function define(IntegrationDefinition $definition): void
    {
        app(IntegrationCatalog::class)->register($definition);
    }

    /**
     * Replace an existing provider's definition (for example to add a field to one of Ìpìlẹ̀'s providers).
     */
    public static function override(IntegrationDefinition $definition): void
    {
        app(IntegrationCatalog::class)->register($definition, override: true);
    }
}
