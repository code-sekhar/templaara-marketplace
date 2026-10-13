<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Listeners\DiscoverMarketplaceNotifications;
use App\Services\Commerce\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('license-api', function (Request $request) {
            $key = hash('sha256', $request->ip() ?? 'unknown');

            return [Limit::perMinute(60)->by($key)->response(fn () => response()->json(['valid' => false, 'code' => 'RATE_LIMITED'], 429)->header('Cache-Control', 'no-store')), Limit::perMinute(20)->by($key.':'.$request->route('operation'))->response(fn () => response()->json(['valid' => false, 'code' => 'RATE_LIMITED'], 429)->header('Cache-Control', 'no-store'))];
        });
        RateLimiter::for('review-write', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id.':reviews'));
        RateLimiter::for('support-write', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id.':support'));
        RateLimiter::for('support-download', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id.':support-download'));
        // Wildcard listeners run after the existing access/earning provisioning listeners.
        Event::listen('App\\Events\\*', function (string $name, array $data): void {
            if ($data && is_object($data[0])) {
                app(DiscoverMarketplaceNotifications::class)->consume($data[0]);
            }
        });
        if (app()->isProduction() && config('commerce.gateway') === 'development') {
            throw new \RuntimeException('Development payments cannot run in production.');
        }
        if (app()->isProduction()) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
