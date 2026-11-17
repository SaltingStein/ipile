<?php

namespace App\Services\Mail;

use App\Models\Setting;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationDefinition;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\IntegrationType;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Which email provider the `dashboard` mailer sends through.
 *
 * Every email integration with a `transport` (ZeptoMail, Resend, or one added with `Integrations::define()`)
 * can send. Email goes through the provider chosen in Admin → Integrations when it is switched on; otherwise
 * through the first switched-on one in catalog order (Ìpìlẹ̀'s first), so sites that never choose keep
 * today's behaviour. With none switched on, the `.env` fallback mailer is used.
 */
class MailProviders
{
    public const PREFERRED = 'mail.preferred_provider';

    /**
     * @var array<class-string, TransportInterface>
     */
    private array $transports = [];

    public function __construct(
        private IntegrationCatalog $catalog,
        private IntegrationManager $integrations,
        private Container $container,
    ) {}

    /**
     * Email providers that can send, in catalog order.
     *
     * @return list<IntegrationDefinition>
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->catalog->ofType(IntegrationType::Mail),
            fn (IntegrationDefinition $definition) => $definition->transport !== null,
        ));
    }

    /**
     * The provider chosen in the dashboard, or null for "the first one switched on".
     */
    public function preferred(): ?string
    {
        return Setting::valueOf(self::PREFERRED);
    }

    public function setPreferred(?string $provider): void
    {
        Setting::store(self::PREFERRED, $provider);
    }

    /**
     * The provider email is sent through right now, or null for the fallback mailer.
     */
    public function active(): ?IntegrationDefinition
    {
        $enabled = array_values(array_filter(
            $this->available(),
            fn (IntegrationDefinition $definition) => $this->integrations->isEnabled($definition->provider),
        ));

        foreach ($enabled as $definition) {
            if ($definition->provider === $this->preferred()) {
                return $definition;
            }
        }

        return $enabled[0] ?? null;
    }

    /**
     * @throws LogicException when the definition has no transport or it is not a mailer transport
     */
    public function transport(IntegrationDefinition $definition): TransportInterface
    {
        $class = $definition->transport ?? throw new LogicException("Integration [{$definition->provider}] has no transport.");

        $transport = $this->transports[$class] ??= $this->container->make($class);

        if (! $transport instanceof TransportInterface) {
            throw new LogicException("The transport for [{$definition->provider}] must implement ".TransportInterface::class.'.');
        }

        return $transport;
    }
}
