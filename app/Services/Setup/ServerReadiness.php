<?php

namespace App\Services\Setup;

use Illuminate\Http\Request;

/**
 * Server checks shown on the first-run /setup page. Anyone can open that page before the site is claimed,
 * so each check reports only pass or fail, never a value, path or version.
 */
class ServerReadiness
{
    /**
     * @return list<array{key: string, label: string, ok: bool, required: bool, fix: string}>
     */
    public function checks(Request $request): array
    {
        $appUrl = (string) config('app.url');

        return [
            [
                'key' => 'https',
                'label' => 'Visitors connect over HTTPS',
                'ok' => $request->isSecure() && str_starts_with($appUrl, 'https://'),
                'required' => true,
                'fix' => 'Install a TLS certificate (for example from Let\'s Encrypt), set `APP_URL` to the `https://` address and redirect plain http to it. Without HTTPS, passwords and the setup key cross the network unencrypted. If a load balancer or proxy handles HTTPS, trust it with `trustProxies` in `bootstrap/app.php`.',
            ],
            [
                'key' => 'app_url',
                'label' => 'APP_URL matches this address',
                'ok' => strcasecmp((string) parse_url($appUrl, PHP_URL_HOST), $request->getHost()) === 0,
                'required' => true,
                'fix' => 'Set `APP_URL` in the server\'s `.env` file to the address people use, such as `https://example.com`. Links in emails, password resets and invitations are built from it.',
            ],
            [
                'key' => 'debug',
                'label' => 'Debug mode is off',
                'ok' => ! config('app.debug'),
                'required' => true,
                'fix' => 'Set `APP_DEBUG=false` in `.env`. With debug mode on, error pages show visitors your code, settings and secrets.',
            ],
            [
                'key' => 'environment',
                'label' => 'The site runs in production mode',
                'ok' => app()->isProduction(),
                'required' => true,
                'fix' => 'Set `APP_ENV=production` in `.env`. Development-only tools and error details stay off in production.',
            ],
            [
                'key' => 'writable',
                'label' => 'The app can write its files',
                'ok' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
                'required' => true,
                'fix' => 'Give the web server\'s user write access to `storage` and `bootstrap/cache`, and no other folder.',
            ],
            [
                'key' => 'queue',
                'label' => 'Background jobs use a queue',
                'ok' => config('queue.default') !== 'sync',
                'required' => false,
                'fix' => 'Set `QUEUE_CONNECTION=database` (or `redis`) and keep `php artisan queue:work` running under a process supervisor such as Supervisor or systemd. Otherwise emails and security checks slow down each page.',
            ],
            [
                'key' => 'storage_link',
                'label' => 'Uploaded files can be shown',
                'ok' => file_exists(public_path('storage')),
                'required' => false,
                'fix' => 'Run `php artisan storage:link` once, so uploaded images such as profile photos and logos load.',
            ],
            [
                'key' => 'optimized',
                'label' => 'Configuration is cached',
                'ok' => app()->configurationIsCached(),
                'required' => false,
                'fix' => 'Run `php artisan optimize` on each deploy. It makes pages faster and stops `.env` being read on every request.',
            ],
        ];
    }
}
