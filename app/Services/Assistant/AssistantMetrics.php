<?php

namespace App\Services\Assistant;

use App\Models\BlogPost;
use App\Models\CampaignPage;
use App\Models\Form;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\Blog\BlogPostStatus;
use App\Services\Payments\PaymentWebhook;
use Closure;
use InvalidArgumentException;

/**
 * Business figures the site assistant can read. A figure is only available when an administrator allowed it in
 * Admin → Site assistant **and** the person asking has the figure's permission, so the assistant never tells
 * anyone more than the admin pages would. Every figure is a count or a total: never names, emails or other
 * personal details.
 *
 * Add your product's own figures with {@see define()}; they appear in Admin → Site assistant for administrators to
 * allow, like Ìpìlẹ̀'s.
 */
class AssistantMetrics
{
    /**
     * Add a business figure of your own. $values runs only after an administrator allowed the figure and the asker's
     * permission was checked, inside the assistant data policy. Return counts and totals, never personal details.
     *
     *     AssistantMetrics::define('wallets.payouts', 'Payouts', 'Number and total of payouts in the last 30 days.',
     *         'view_billing', fn () => ['payouts' => Payout::query()->recent()->count()]);
     *
     * @param  Closure(): array<mixed>  $values
     *
     * @api
     */
    public static function define(string $key, string $label, string $description, string $permission, Closure $values): void
    {
        if (isset(self::builtIn()[$key])) {
            throw new InvalidArgumentException("[{$key}] is one of Ìpìlẹ̀'s own assistant figures.");
        }

        app(AssistantExtensions::class)->defineMetric($key, $label, $description, $permission, $values);
    }

    /**
     * Ìpìlẹ̀'s figures plus the ones builders defined.
     *
     * @return array<string, array{label: string, description: string, permission: string}>
     */
    public static function definitions(): array
    {
        $extra = array_map(
            fn (array $metric) => ['label' => $metric['label'], 'description' => $metric['description'], 'permission' => $metric['permission']],
            app(AssistantExtensions::class)->metrics(),
        );

        return [...self::builtIn(), ...$extra];
    }

    /**
     * @return array<string, array{label: string, description: string, permission: string}>
     */
    private static function builtIn(): array
    {
        return [
            'users.growth' => ['label' => 'Accounts', 'description' => 'Total accounts, and new accounts in the last 7 and 30 days.', 'permission' => 'manage_users'],
            'teams.total' => ['label' => 'Teams', 'description' => 'Number of teams and new teams in the last 30 days.', 'permission' => 'manage_users'],
            'forms.responses' => ['label' => 'Form responses', 'description' => 'Responses to each site form, in all and in the last 30 days.', 'permission' => 'manage_forms'],
            'campaigns.performance' => ['label' => 'Campaign results', 'description' => 'Views, button clicks and sign-ups for each campaign page.', 'permission' => 'manage_campaigns'],
            'blog.posts' => ['label' => 'Blog posts', 'description' => 'Published, scheduled and draft posts.', 'permission' => 'manage_blog'],
            'payments.summary' => ['label' => 'Payments', 'description' => 'Number and total of successful payments in the last 30 days, by currency.', 'permission' => 'view_billing'],
        ];
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    public function __construct(private AssistantSettings $settings) {}

    /**
     * Figures this person may ask about: allowed by an administrator and covered by their own permissions.
     *
     * @return array<string, array{label: string, description: string, permission: string}>
     */
    public function availableTo(User $user): array
    {
        return array_filter(
            array_intersect_key(self::definitions(), array_flip($this->settings->metrics())),
            fn (array $definition) => $user->can($definition['permission']),
        );
    }

    /**
     * The figure's current values, or null when this person may not read it.
     *
     * @return array<string, mixed>|null
     */
    public function read(User $user, string $key): ?array
    {
        return isset($this->availableTo($user)[$key]) ? $this->values($key) : null;
    }

    /**
     * The figure's values, without checking who asks: call {@see availableTo()} first. Runs only data queries
     * (no settings or permission lookups), so it works under AssistantDataPolicy.
     *
     * @return array<string, mixed>|null
     */
    public function values(string $key): ?array
    {
        return match ($key) {
            'users.growth' => [
                'total_accounts' => User::query()->where('is_guest', false)->count(),
                'new_last_7_days' => User::query()->where('is_guest', false)->where('created_at', '>=', now()->subDays(7))->count(),
                'new_last_30_days' => User::query()->where('is_guest', false)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'teams.total' => [
                'teams' => Team::query()->count(),
                'new_last_30_days' => Team::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            // Columns are named before withCount, which would otherwise add forms.* (refused by AssistantDataPolicy).
            'forms.responses' => Form::query()->forSite()->select(['id', 'title', 'status'])->withCount([
                'submissions',
                'submissions as last_30_days' => fn ($query) => $query->where('created_at', '>=', now()->subDays(30)),
            ])->orderByDesc('submissions_count')->limit(20)->get()
                ->map(fn (Form $form) => ['form' => $form->title, 'status' => $form->status->value, 'responses' => $form->submissions_count, 'last_30_days' => $form->last_30_days])
                ->all(),
            'campaigns.performance' => CampaignPage::query()->latest('updated_at')->limit(20)->get(['id', 'title', 'status', 'views', 'cta_clicks', 'conversions'])
                ->map(fn (CampaignPage $page) => ['page' => $page->title, 'status' => $page->status->value, 'views' => $page->views, 'button_clicks' => $page->cta_clicks, 'sign_ups' => $page->conversions, 'sign_up_rate_percent' => $page->conversionRate()])
                ->all(),
            'blog.posts' => [
                'published' => BlogPost::query()->visible()->count(),
                'scheduled' => BlogPost::query()->where('status', BlogPostStatus::Published)->where('published_at', '>', now())->count(),
                'drafts' => BlogPost::query()->where('status', BlogPostStatus::Draft)->count(),
            ],
            'payments.summary' => Payment::query()
                ->where('status', PaymentWebhook::STATUS_SUCCESSFUL)
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('currency, count(*) as payments, sum(amount) as total')
                ->groupBy('currency')
                ->get()
                ->map(fn (Payment $row) => ['currency' => $row->currency, 'payments' => (int) $row->getAttribute('payments'), 'total' => (string) $row->getAttribute('total')])
                ->all(),
            default => isset(app(AssistantExtensions::class)->metrics()[$key])
                ? (app(AssistantExtensions::class)->metrics()[$key]['values'])()
                : null,
        };
    }
}
