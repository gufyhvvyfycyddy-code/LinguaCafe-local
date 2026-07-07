<?php

namespace App\Providers;

use App\Services\AiStudyCardV6DisabledProviderAdapter;
use App\Services\AiStudyCardV6OpenAiCompatibleHttpTransport;
use App\Services\AiStudyCardV6OpenAiCompatibleProviderAdapter;
use App\Services\AiStudyCardV6ProviderInterface;
use App\Services\AiStudyCardV6ProviderTransportInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // V6 provider boundary: disabled by default. A real adapter is bound
        // only when the user manually configures the local environment.
        $this->app->bind(AiStudyCardV6ProviderTransportInterface::class, AiStudyCardV6OpenAiCompatibleHttpTransport::class);

        $this->app->bind(AiStudyCardV6ProviderInterface::class, function ($app) {
            if (
                config('ai_study_card_v6.provider.external_requests_enabled')
                && config('ai_study_card_v6.provider.allowed_adapter') === 'openai_compatible'
            ) {
                return $app->make(AiStudyCardV6OpenAiCompatibleProviderAdapter::class);
            }

            return $app->make(AiStudyCardV6DisabledProviderAdapter::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
