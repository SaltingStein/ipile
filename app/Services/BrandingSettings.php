<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class BrandingSettings
{
    public const NAME = 'branding.name';

    public const ORGANISATION_NAME = 'branding.organisation_name';

    public const SERVICE_NAME = 'branding.service_name';

    public const CONTACT_EMAIL = 'branding.contact_email';

    public const VERSION = 'branding.version';

    public const LOGO = 'branding.logo_path';

    public const LAUNCHED_ON = 'branding.launched_on';

    public const ABOUT_PAGE_VISIBLE = 'site.about_page_visible';

    public const CREDIT_VISIBLE = 'site.boilerplate_credit_visible';

    /**
     * The boilerplate's own name. It never follows the brand name: the brand identifies your product, this credits what it is built on.
     */
    public const BOILERPLATE_NAME = 'Ìpìlẹ̀';

    /**
     * @return array{name: string, organisation_name: ?string, service_name: ?string, contact_email: ?string, version: string, logo_url: ?string, launched_on: ?string}
     */
    public function publicDetails(): array
    {
        $path = Setting::valueOf(self::LOGO);

        return [
            'name' => $this->appName(),
            'organisation_name' => Setting::valueOf(self::ORGANISATION_NAME),
            'service_name' => Setting::valueOf(self::SERVICE_NAME),
            'contact_email' => Setting::valueOf(self::CONTACT_EMAIL),
            'version' => Setting::valueOf(self::VERSION, '0.1.0'),
            'logo_url' => $path ? Storage::disk('public')->url($path) : null,
            'launched_on' => Setting::valueOf(self::LAUNCHED_ON),
        ];
    }

    /**
     * The app name shown across the product, in emails, on payment pages and in authenticator apps.
     */
    public function appName(): string
    {
        return Setting::valueOf(self::NAME, 'Ìpìlẹ̀');
    }

    /**
     * The name of the service the legal pages cover; falls back to the app name until an admin sets one.
     */
    public function serviceName(): string
    {
        return Setting::valueOf(self::SERVICE_NAME) ?: $this->appName();
    }

    /**
     * The name of the business behind the app; falls back to the app name until an admin sets one.
     */
    public function organisationName(): string
    {
        return Setting::valueOf(self::ORGANISATION_NAME) ?: $this->appName();
    }

    /**
     * Values for the bracketed placeholders admins can write in legal pages, matched case-insensitively.
     * A placeholder without a value is left as written so it stays visible for review.
     *
     * @return array<string, string>
     */
    public function legalPlaceholders(): array
    {
        $contactEmail = Setting::valueOf(self::CONTACT_EMAIL);

        return array_filter([
            '[service name]' => $this->serviceName(),
            '[organisation name]' => $this->organisationName(),
            '[organization name]' => $this->organisationName(),
            '[contact email]' => $contactEmail,
            '[privacy contact]' => $contactEmail,
        ], fn (?string $value): bool => filled($value));
    }

    /**
     * Replaces the legal placeholders in the given text with the current branding values.
     */
    public function fillLegalPlaceholders(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $placeholders = $this->legalPlaceholders();

        return str_ireplace(array_keys($placeholders), array_values($placeholders), $text);
    }

    /**
     * Whether the public "About this boilerplate" page is visible.
     */
    public function aboutPageVisible(): bool
    {
        return Setting::valueOf(self::ABOUT_PAGE_VISIBLE, '1') === '1';
    }

    /**
     * Whether the footer shows the "Built on Ìpìlẹ̀" credit.
     */
    public function creditVisible(): bool
    {
        return Setting::valueOf(self::CREDIT_VISIBLE, '1') === '1';
    }

    /**
     * @param  ?string  $launchedOn  Launch date as Y-m-d, shown in the public footer and copyright line.
     * @param  ?bool  $aboutPageVisible  Show the public "About this boilerplate" page; null keeps the current choice.
     * @param  ?bool  $creditVisible  Show the "Built on Ìpìlẹ̀" footer credit; null keeps the current choice.
     * @param  ?string  $organisationName  The business behind the app, used in legal pages and the copyright line; null falls back to the app name.
     * @param  ?string  $serviceName  The service the legal pages cover; null falls back to the app name.
     * @param  ?string  $contactEmail  Public contact address used in legal pages.
     */
    public function update(string $name, string $version, ?string $launchedOn, ?UploadedFile $logo, bool $removeLogo, ?bool $aboutPageVisible = null, ?bool $creditVisible = null, ?string $organisationName = null, ?string $serviceName = null, ?string $contactEmail = null): void
    {
        $oldPath = Setting::valueOf(self::LOGO);
        $newPath = $logo?->store('branding', 'public');

        if ($logo && ! $newPath) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be stored. Please try again.']);
        }

        try {
            DB::transaction(function () use ($name, $version, $launchedOn, $newPath, $removeLogo, $aboutPageVisible, $creditVisible, $organisationName, $serviceName, $contactEmail): void {
                Setting::store(self::NAME, $name);
                Setting::store(self::ORGANISATION_NAME, $organisationName);
                Setting::store(self::SERVICE_NAME, $serviceName);
                Setting::store(self::CONTACT_EMAIL, $contactEmail);
                Setting::store(self::VERSION, $version);
                Setting::store(self::LAUNCHED_ON, $launchedOn);

                if ($aboutPageVisible !== null) {
                    Setting::store(self::ABOUT_PAGE_VISIBLE, $aboutPageVisible ? '1' : '0');
                }

                if ($creditVisible !== null) {
                    Setting::store(self::CREDIT_VISIBLE, $creditVisible ? '1' : '0');
                }

                if ($newPath || $removeLogo) {
                    Setting::store(self::LOGO, $newPath ?: null);
                }
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if (($newPath || $removeLogo) && $oldPath && str_starts_with($oldPath, 'branding/')) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}
