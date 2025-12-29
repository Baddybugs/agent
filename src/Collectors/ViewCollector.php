<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;

/**
 * View Rendering Collector
 * 
 * Monitors view rendering performance:
 * - Rendering time per view
 * - Slow views detection
 * - Most used views
 * - View composition tracking
 */
class ViewCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    
    protected array $viewTimings = [];
    protected array $slowViews = [];

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!config('baddybugs.track_view_rendering', true)) {
            return;
        }

        $this->trackViewComposing();
        $this->trackViewRendering();
    }

    protected function trackViewComposing(): void
    {
        Event::listen('composing:*', function ($view, $data = null) {
            // Handle both old and new event formats
            if ($view instanceof View) {
                $viewName = $view->getName();
            } else {
                $viewName = $view;
            }

            // Store start time for this view
            $this->viewTimings[$viewName] = [
                'started_at' => microtime(true),
                'view_name' => $viewName,
            ];
        });
    }

    protected function trackViewRendering(): void
    {
        // Track when view is created
        Event::listen('creating:*', function ($view, $data = null) {
            if ($view instanceof View) {
                $viewName = $view->getName();
                
                if (!isset($this->viewTimings[$viewName])) {
                    $this->viewTimings[$viewName] = [
                        'started_at' => microtime(true),
                        'view_name' => $viewName,
                    ];
                }
            }
        });
    }

    /**
     * Record view rendering completion
     */
    public function recordViewRendered(string $viewName, float $startTime): void
    {
        $duration = (microtime(true) - $startTime) * 1000; // ms
        $threshold = config('baddybugs.slow_view_threshold', 200); // ms

        $data = [
            'view_name' => $viewName,
            'duration_ms' => round($duration, 2),
            'is_slow' => $duration > $threshold,
            'url' => request()->fullUrl(),
            'route' => optional(request()->route())->getName(),
        ];

        // Record only slow views or sample others
        if ($duration > $threshold) {
            $this->slowViews[] = $viewName;
            
            $this->baddybugs->record('view', 'slow_view', array_merge($data, [
                'threshold_ms' => $threshold,
                'severity' => $duration > ($threshold * 2) ? 'high' : 'medium',
            ]));
        } else {
            // Sample fast views (10%)
            if (mt_rand(0, 100) < 10) {
                $this->baddybugs->record('view', 'rendered', $data);
            }
        }
    }

    /**
     * Manually track a view rendering
     */
    public function track(string $viewName): void
    {
        $startTime = $this->viewTimings[$viewName]['started_at'] ?? microtime(true);
        $this->recordViewRendered($viewName, $startTime);
        
        unset($this->viewTimings[$viewName]);
    }

    /**
     * Get slow views statistics
     */
    public function getSlowViews(): array
    {
        $counts = array_count_values($this->slowViews);
        arsort($counts);
        
        return [
            'total_slow_views' => count($this->slowViews),
            'unique_slow_views' => count($counts),
            'top_slow_views' => array_slice($counts, 0, 10, true),
        ];
    }

    /**
     * Wrap view rendering to track automatically
     */
    public static function wrapView(View $view): View
    {
        $collector = app(self::class);
        
        $viewName = $view->getName();
        $startTime = microtime(true);
        
        // Hook into rendering
        $view->render();
        
        $collector->recordViewRendered($viewName, $startTime);
        
        return $view;
    }
}
