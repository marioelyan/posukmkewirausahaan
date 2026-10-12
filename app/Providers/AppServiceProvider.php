<?php

namespace App\Providers;

use App\Services\BusinessContext;
use App\Support\ProductionSecurityBaseline;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(BusinessContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(env('API_RATE_LIMIT_PER_MINUTE', 120))
                ->by($request->user()?->id ?: $request->ip());
        });

        // API documentation (Scramble). Docs are public by default (open source
        // project). Set SCRAMBLE_DOCS_TOKEN in production to require a token:
        // send it via the `X-Docs-Token` header (or `?token=` query param).
        Gate::define('viewApiDocs', function () {
            $token = config('scramble.docs_token');

            if (blank($token)) {
                return true;
            }

            $given = request()->header('X-Docs-Token', request()->query('token'));

            return is_string($given) && hash_equals($token, $given);
        });

        // Never let a resolved business context bleed into the next request when
        // the container/process is reused (tests, Octane, queue workers).
        $this->app['events']->listen(RequestHandled::class, function () {
            $this->app->make(BusinessContext::class)->clear();
        });

        $issues = ProductionSecurityBaseline::issues();

        if ($issues !== []) {
            Log::warning('Production security baseline check failed.', [
                'issues' => $issues,
            ]);
        }
    }
}
