<?php

namespace App\Providers;

use App\Services\AiStudyCardV6DisabledProviderAdapter;
use App\Services\AiStudyCardV6ProviderInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // V6-2 provider adapter boundary: production is disabled by default.
        // Real provider integration requires a separate approved task and must
        // not be hidden in the existing V1-V5 workflow.
        $this->app->bind(AiStudyCardV6ProviderInterface::class, AiStudyCardV6DisabledProviderAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
