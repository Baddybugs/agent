<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Livewire Event Collector
 * 
 * Monitors Livewire 3 component lifecycle and captures:
 * - Network failures (message.failed)
 * - Slow/timeout requests (message.processing)
 * - Component errors during dehydration
 * - Optional: Component initialization tracking
 * 
 * This works automatically with:
 * - Vanilla Livewire components
 * - FilamentPHP (resources, widgets, actions, modals, tables)
 * 
 * Zero overhead if disabled via config.
 */
class LivewireCollector implements CollectorInterface
{
    /**
     * The BaddyBugs instance.
     */
    protected BaddyBugs $baddybugs;

    /**
     * Track component processing start times.
     */
    protected array $processingTimes = [];

    /**
     * Create a new Livewire collector.
     */
    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    /**
     * Boot the collector.
     */
    public function boot(): void
    {
        // Only boot if Livewire is installed and monitoring is enabled
        if (!$this->shouldBoot()) {
            return;
        }

        $this->registerEventListeners();
    }

    /**
     * Check if the collector should boot.
     */
    protected function shouldBoot(): bool
    {
        // Check if Livewire monitoring is enabled
        if (!config('baddybugs.livewire_monitoring_enabled', false)) {
            return false;
        }

        // Check if Livewire is installed
        if (!class_exists(\Livewire\Livewire::class)) {
            return false;
        }

        return true;
    }

    /**
     * Register Livewire event listeners.
     */
    protected function registerEventListeners(): void
    {
        try {
            // Listen for component initialization (optional, high volume)
            if (config('baddybugs.livewire_track_initialization', false)) {
                Event::listen('livewire.component.initialized', function ($component, $request) {
                    $this->handleComponentInitialized($component, $request);
                });
            }

            // Listen for message processing start (to detect slow/timeout requests)
            Event::listen('livewire.message.processing', function ($message, $component) {
                $this->handleMessageProcessing($message, $component);
            });

            // Listen for message processing completion
            Event::listen('livewire.message.processed', function ($message, $response) {
                $this->handleMessageProcessed($message, $response);
            });

            // Listen for failed messages (network errors, server errors)
            Event::listen('livewire.message.failed', function ($message, $response) {
                $this->handleMessageFailed($message, $response);
            });

            // Listen for component dehydration (can catch hydration errors)
            Event::listen('livewire.component.dehydrate', function ($component, $response) {
                $this->handleComponentDehydrate($component, $response);
            });

            // Listen for component dehydration exceptions
            Event::listen('livewire.component.dehydrate.exception', function ($exception, $component, $response) {
                $this->handleDehydrateException($exception, $component, $response);
            });

        } catch (\Throwable $e) {
            // Silently fail - monitoring should never break the app
        }
    }

    /**
     * Handle component initialization.
     */
    protected function handleComponentInitialized($component, $request): void
    {
        try {
            $this->baddybugs->record('livewire_component', 'initialized', [
                'component' => $this->getComponentName($component),
                'component_id' => $this->getComponentId($component),
                'url' => request()->fullUrl(),
                'user_id' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Handle message processing start.
     */
    protected function handleMessageProcessing($message, $component): void
    {
        try {
            $componentId = $this->getComponentId($component);
            
            // Store the start time to calculate duration later
            $this->processingTimes[$componentId] = microtime(true);

        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Handle message processing completion.
     */
    protected function handleMessageProcessed($message, $response): void
    {
        try {
            // Extract component info from message
            $componentName = $message['fingerprint']['name'] ?? 'unknown';
            $componentId = $message['fingerprint']['id'] ?? 'unknown';
            
            // Calculate duration if we have a start time
            $duration = null;
            if (isset($this->processingTimes[$componentId])) {
                $duration = (microtime(true) - $this->processingTimes[$componentId]) * 1000; // Convert to ms
                unset($this->processingTimes[$componentId]);
            }

            // Check if duration exceeds timeout threshold
            $threshold = config('baddybugs.livewire_timeout_threshold', 10000);
            if ($duration && $duration > $threshold) {
                $this->baddybugs->record('livewire_performance', 'slow_request', [
                    'component' => $componentName,
                    'component_id' => $componentId,
                    'duration_ms' => round($duration, 2),
                    'threshold_ms' => $threshold,
                    'updates' => $message['updates'] ?? [],
                    'url' => request()->fullUrl(),
                    'user_id' => auth()->id(),
                ]);
            }

        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Handle failed messages.
     */
    protected function handleMessageFailed($message, $response): void
    {
        try {
            $componentName = $message['fingerprint']['name'] ?? 'unknown';
            $componentId = $message['fingerprint']['id'] ?? 'unknown';

            // Calculate duration if we have a start time
            $duration = null;
            if (isset($this->processingTimes[$componentId])) {
                $duration = (microtime(true) - $this->processingTimes[$componentId]) * 1000;
                unset($this->processingTimes[$componentId]);
            }

            $this->baddybugs->record('livewire_error', 'message_failed', [
                'component' => $componentName,
                'component_id' => $componentId,
                'duration_ms' => $duration ? round($duration, 2) : null,
                'updates' => $message['updates'] ?? [],
                'calls' => $message['calls'] ?? [],
                'response_status' => $response->getStatusCode() ?? null,
                'url' => request()->fullUrl(),
                'user_id' => auth()->id(),
            ]);

        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Handle component dehydration.
     */
    protected function handleComponentDehydrate($component, $response): void
    {
        // This event fires on every component render
        // We only record if there's an issue during dehydration
        // The actual error handling is done in handleDehydrateException
    }

    /**
     * Handle dehydration exceptions.
     */
    protected function handleDehydrateException($exception, $component, $response): void
    {
        try {
            $this->baddybugs->record('livewire_error', 'dehydration_exception', [
                'component' => $this->getComponentName($component),
                'component_id' => $this->getComponentId($component),
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'url' => request()->fullUrl(),
                'user_id' => auth()->id(),
            ]);

        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Get the component name.
     */
    protected function getComponentName($component): string
    {
        try {
            if (is_object($component)) {
                return get_class($component);
            }
            
            if (is_array($component) && isset($component['fingerprint']['name'])) {
                return $component['fingerprint']['name'];
            }

            return 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * Get the component ID.
     */
    protected function getComponentId($component): string
    {
        try {
            if (is_object($component) && method_exists($component, 'getId')) {
                return $component->getId();
            }
            
            if (is_array($component) && isset($component['fingerprint']['id'])) {
                return $component['fingerprint']['id'];
            }

            return 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
