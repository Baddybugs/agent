<?php

namespace BaddyBugs\Agent;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use BaddyBugs\Agent\Commands\AgentCommand;
use BaddyBugs\Agent\Commands\SendCommand;
use BaddyBugs\Agent\Directives\FrontendDirectives;
use BaddyBugs\Agent\Middleware\InjectTraceIdMiddleware;

class BaddyBugsAgentServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/baddybugs.php' => config_path('baddybugs.php'),
            ], 'baddybugs-config');

            $this->commands([
                AgentCommand::class,
                SendCommand::class,
            ]);
            
            // Add information to `php artisan about` if available
            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('BaddyBugs', fn () => [
                    'Version' => '1.0.0', 
                    'Enabled' => config('baddybugs.enabled') ? 'YES' : 'NO',
                    'Driver' => config('baddybugs.driver', 'log')
                ]);
            }
        }

        // Only boot collectors if enabled and not in restricted environments
        if ($this->shouldBootBaddyBugs()) {
            // Initialize the BaddyBugs singleton
            $baddybugs = $this->app->make(BaddyBugs::class);
            $baddybugs->bootCollectors();

            // Register global middleware for web requests to handle termination
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            $kernel->pushMiddleware(\BaddyBugs\Agent\Middleware\InjectBaddyBugs::class);
            
            // Register frontend trace_id middleware if frontend monitoring is enabled
            if (config('baddybugs.frontend_enabled', false)) {
                $kernel->pushMiddleware(InjectTraceIdMiddleware::class);
            }
            
            // Register deployment detection middleware if regression analysis is enabled
            if (config('baddybugs.regression_analysis_enabled', true)) {
                $kernel->pushMiddleware(\BaddyBugs\Agent\Middleware\DetectDeployment::class);
            }
        }
        
        // Register Blade directives for frontend monitoring
        $this->registerBladeDirectives();
        
        // Boot Livewire monitoring if enabled
        $this->bootLivewireMonitoring();
        
        // Boot Feature Collector if enabled
        $this->bootFeatureCollector();
        
        // Boot Security Collector if enabled
        $this->bootSecurityCollector();
        
        // Register Log Handler if enabled
        $this->registerLogHandler();
        
        // Boot Health Collector if enabled
        $this->bootHealthCollector();
        
        // Boot View Collector if enabled
        $this->bootViewCollector();
        
        // Boot Middleware Collector if enabled
        $this->bootMiddlewareCollector();
        
        // Boot Timeline Collector if enabled
        $this->bootTimelineCollector();


    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/baddybugs.php', 'baddybugs');

        $this->app->singleton(BaddyBugs::class, function ($app) {
            return new BaddyBugs($app);
        });
        
        $this->app->alias(BaddyBugs::class, 'baddybugs');

        // Bind Sender
        $this->app->bind(Sender\SenderInterface::class, Sender\HttpSender::class);

        // Bind Buffer based on driver config
        $this->app->bind(Buffers\BufferInterface::class, function ($app) {
            $driver = config('baddybugs.buffer_driver', 'memory');
            
            return match ($driver) {
                'file' => new Buffers\FileBuffer(),
                'redis' => new Buffers\RedisBuffer(),
                default => new Buffers\MemoryBuffer($app->make(Sender\SenderInterface::class)),
            };
        });
    }

    /**
     * Check if BaddyBugs should boot.
     *
     * @return bool
     */
    protected function shouldBootBaddyBugs(): bool
    {
        if (!config('baddybugs.enabled', false)) {
            return false;
        }

        // Example: logic to disable in certain conditions (e.g., specific running console commands)
        // But generally we rely on the config 'enabled' flag and environment checks.
        
        return true;
    }
    
    /**
     * Register Blade directives for frontend monitoring.
     *
     * @return void
     */
    protected function registerBladeDirectives(): void
    {
        // Only register if frontend monitoring is enabled
        if (!config('baddybugs.enabled', false) || !config('baddybugs.frontend_enabled', false)) {
            return;
        }
        
        FrontendDirectives::register();
    }
    
    /**
     * Boot Livewire monitoring if enabled.
     *
     * @return void
     */
    protected function bootLivewireMonitoring(): void
    {
        // Only boot if BaddyBugs and Livewire monitoring are both enabled
        if (!config('baddybugs.enabled', false)) {
            return;
        }
        
        if (!config('baddybugs.livewire_monitoring_enabled', false)) {
            return;
        }
        
        // Check if Livewire is installed
        if (!class_exists(\Livewire\Livewire::class)) {
            return;
        }
        
        try {
            // Initialize the Livewire collector
            $collector = $this->app->make(Collectors\LivewireCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silently fail - monitoring should never break the app
        }
    }

    /**
     * Register the Feature Collector
     */
    protected function bootFeatureCollector(): void
    {
        if (!config('baddybugs.feature_tracking_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\FeatureCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Register the Security Collector
     */
    protected function bootSecurityCollector(): void
    {
        if (!config('baddybugs.security_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\SecurityCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Register the BaddyBugs Log Handler with Monolog
     */
    protected function registerLogHandler(): void
    {
        if (!config('baddybugs.logs_enabled', true)) {
            return;
        }

        try {
            $logger = $this->app->make('log');
            $minLevel = config('baddybugs.logs_min_level', 'warning');
            
            $handler = new \BaddyBugs\Agent\Handlers\BaddyBugsLogHandler(
                $this->app->make(BaddyBugs::class),
                $minLevel
            );

            $logger->pushHandler($handler);
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Register the Health Collector
     */
    protected function bootHealthCollector(): void
    {
        if (!config('baddybugs.health_monitoring_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\HealthCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Register the View Collector
     */
    protected function bootViewCollector(): void
    {
        if (!config('baddybugs.track_view_rendering', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\ViewCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Register the Middleware Collector
     */
    protected function bootMiddlewareCollector(): void
    {
        if (!config('baddybugs.track_middleware_timing', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\MiddlewareCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

    /**
     * Register the Timeline Collector
     */
    protected function bootTimelineCollector(): void
    {
        if (!config('baddybugs.timeline_enabled', true)) {
            return;
        }

        try {
            $collector = $this->app->make(Collectors\TimelineCollector::class);
            $collector->boot();
        } catch (\Throwable $e) {
            // Silent failure
        }
    }

}

