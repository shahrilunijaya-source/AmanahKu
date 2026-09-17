<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Keep the bespoke Amanahku design for every auth screen instead of Fortify's defaults.
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        // A company can turn passkey sign-in off (security.passkey = 'off'). The
        // Passkeys package has no notion of tenants, so gate it here: refuse when ANY
        // tenant the user belongs to currently resolves it to 'off', unless the user is
        // a super admin, who is never gated by a single company's settings. Throwing
        // (rather than returning false) surfaces our message on the login page instead
        // of PasskeyLoginController's generic "Unable to sign in with this account."
        Passkeys::authorizeLoginUsing(function (Request $request, $user, $passkey): bool {
            /** @var User $user */
            if ($user->isSuperAdmin()) {
                return true;
            }

            $features = app(FeatureManager::class);
            foreach ($user->tenants as $tenant) {
                // tenants() is a BelongsToMany with no generic PHPDoc, so static analysis
                // sees a bare Model here; it is always a Tenant at runtime.
                if (! $tenant instanceof Tenant) {
                    continue;
                }

                if ($features->value($tenant, 'security.passkey') === 'off') {
                    throw InvalidPasskeyException::make('Your company has turned off passkey sign-in. Please use your password.');
                }
            }

            return true;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Throttle self-serve sign-ups per IP to blunt automated account creation.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
