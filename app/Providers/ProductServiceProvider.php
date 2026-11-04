<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Your product's additions to Ìpìlẹ̀. Register them here instead of editing Ìpìlẹ̀'s own files, so
 * boilerplate updates merge cleanly. Ìpìlẹ̀ itself never changes this file.
 *
 * Every extension point, with an example, is listed in project-docs/developer-guide.md → "Extension points":
 *
 *     Permissions::define('view_reports', 'See the reports pages', grantTo: ['admin']);
 *     Notify::define(new NotificationEvent('reports.ready', 'Reports', 'Report ready', ...));
 *     Integrations::define(new IntegrationDefinition('acme_mail', IntegrationType::Mail, 'Acme Mail', ...));
 *     Security::define(new SecurityEventDefinition('reports.exported', 'reports', 'Report exported', ...));
 *     AssistantDataPolicy::block('invoices', ['bank_account_number']);
 */
class ProductServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
