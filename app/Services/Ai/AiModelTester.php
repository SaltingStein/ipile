<?php

namespace App\Services\Ai;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

use function Laravel\Ai\agent;

/**
 * "Test connection" for an AI model provider: sends a tiny prompt through `laravel/ai`, which checks the key,
 * the URL and the model name together. It costs a handful of tokens. A dropped connection is retried once, so an
 * unstable network does not look like wrong settings.
 */
abstract class AiModelTester implements IntegrationTester
{
    use RetriesAiConnections;

    /**
     * The integration provider key, e.g. "openai".
     */
    abstract protected function provider(): string;

    public function test(array $config): IntegrationTestResult
    {
        try {
            $provider = app(AiModels::class)->fromConfig($this->provider(), $config);
            $reply = $this->withConnectionRetries(fn () => agent('You are a connection check. Reply with the single word OK.')
                ->prompt('Connection check.', provider: [$provider], timeout: 30), attempts: 2);
        } catch (RateLimitedException) {
            return IntegrationTestResult::passed(__('The provider accepted the settings but is rate-limiting requests right now.'));
        } catch (InsufficientCreditsException) {
            return IntegrationTestResult::failed(__('The provider accepted the key but the account has no credit or quota left.'));
        } catch (ProviderConnectionException $exception) {
            Log::warning('AI model connection test could not connect.', ['provider' => $this->provider(), 'exception' => $exception->getPrevious() ?? $exception]);

            return IntegrationTestResult::failed(self::timedOut($exception)
                ? __('The provider did not answer in time, twice. The settings may be fine: this server’s connection to the provider looks slow or unstable. Try again, and check the server’s internet connection or firewall if it keeps happening.')
                : __('Could not reach the provider. Check the URL, that the server is running, and this server’s internet connection.'));
        } catch (ProviderOverloadedException) {
            return IntegrationTestResult::failed(__('The provider is overloaded. Try again in a minute.'));
        } catch (RequestException $exception) {
            return IntegrationTestResult::failed(match ($exception->response->status()) {
                401, 403 => __('The provider rejected the API key.'),
                404 => __('The provider does not know this model or URL. Check the model name and URL.'),
                default => __('The provider returned an error (HTTP :status).', ['status' => $exception->response->status()]),
            });
        } catch (Throwable $exception) {
            Log::warning('AI model connection test failed.', ['provider' => $this->provider(), 'exception' => $exception]);

            return IntegrationTestResult::failed(__('The test did not complete. Check the settings; details are in the application log.'));
        }

        return IntegrationTestResult::passed(__('The model replied (model: :model).', ['model' => $reply->meta->model ?? 'default']));
    }

    /**
     * Whether the connection was made but no answer came in time (cURL error 28), rather than refused outright.
     */
    private static function timedOut(ProviderConnectionException $exception): bool
    {
        return str_contains((string) $exception->getPrevious()?->getMessage(), 'cURL error 28');
    }
}
