<?php

namespace App\Providers;

use App\Models\AppNotification;
use App\Models\PersonalAccessToken;
use App\Services\Ai\AiProvider;
use App\Services\Ai\CannedAiProvider;
use App\Services\Ai\ClaudeAiProvider;
use App\Services\FeatureManager;
use App\Services\OidcClient;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
        $this->app->singleton(FeatureManager::class);

        // Enterprise SSO relying-party, built from config/services.php oidc block.
        $this->app->bind(OidcClient::class, fn () => OidcClient::fromConfig());

        // Resolve the workforce assistant: live Claude when configured, canned otherwise.
        $this->app->singleton(AiProvider::class, function () {
            $canned = new CannedAiProvider;
            $key = config('services.ai.anthropic_key');

            if (config('services.ai.driver') === 'claude' && $key) {
                return new ClaudeAiProvider($key, config('services.ai.model'), $canned);
            }

            return $canned;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use the tenant-aware token model so /api/v1 calls resolve to the token's tenant.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Surface deliverability risk in `php artisan about` (AK-CONFIG-02): a production
        // deploy still on the `log` mailer silently drops password-reset + invite emails to
        // the log file instead of sending them. Flag it loudly rather than discover it when
        // a locked-out user never receives their reset link.
        AboutCommand::add('Amanahku', fn () => [
            'Mail transport' => config('mail.default'),
            'Mail deliverability' => $this->app->isProduction() && config('mail.default') === 'log'
                ? 'WARNING: mailer is "log" in production — reset & invite emails are written to the log, not sent'
                : 'OK',
        ]);

        // Baseline password strength for every set-password path (register, reset, forced
        // first-login change, activation) — Laravel's 8-char default is too weak, and it
        // undermines the forced-rotation off a one-time password (AK-SEC-11). The breach
        // check (uncompromised) is production-only so tests/local never call the k-anonymity
        // HaveIBeenPwned API.
        Password::defaults(function () {
            $rule = Password::min(10)->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Share the current user's notifications with the app header bell.
        View::composer('partials.header', function ($view) {
            $notifications = collect();
            $unreadCount = 0;

            if (Auth::check() && app(CurrentTenant::class)->check()) {
                $uid = Auth::id();
                $tid = app(CurrentTenant::class)->id();
                $notifications = AppNotification::where('user_id', $uid)->where('tenant_id', $tid)->latest()->take(8)->get();
                $unreadCount = AppNotification::where('user_id', $uid)->where('tenant_id', $tid)->whereNull('read_at')->count();
            }

            $view->with('notifications', $notifications)->with('unreadCount', $unreadCount);
        });

        // Share the changelog (What's New) with the surfaces that render it. Newest first;
        // `latestVersion` drives the per-user "New" badge. Single source: config/changelog.php.
        View::composer(['partials.feedback', 'partials.sidebar'], function ($view) {
            $releases = config('changelog.releases', []);
            $view->with('releases', $releases)
                ->with('latestVersion', $releases[0]['version'] ?? null);
        });
    }
}
