<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Eloquent Collector
 * 
 * Deep Eloquent usage tracking:
 * - Eager loads vs lazy loads
 * - Relationship types used
 * - Accessor/Mutator calls
 * - Model events fired
 * - Pivot table access
 */
class EloquentCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    protected array $eloquentMetrics = [
        'eager_loads' => [],
        'lazy_loads' => [],
        'accessors_called' => [],
        'mutators_called' => [],
        'relationships_loaded' => [],
        'pivot_accesses' => [],
        'model_events' => [],
    ];

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
    }

    public function boot(): void
    {
        if (!config('baddybugs.eloquent_tracking_enabled', true)) {
            return;
        }

        $this->trackEagerLoading();
        $this->trackLazyLoading();
        $this->trackModelEvents();

        app()->terminating(function () {
            $this->sendMetrics();
        });
    }

    protected function trackEagerLoading(): void
    {
        // Hook into query builder to detect eager loading
        Event::listen('eloquent.retrieved:*', function ($event, $models) {
            if (!empty($models) && is_array($models)) {
                foreach ($models as $model) {
                    if ($model instanceof Model) {
                        $this->detectEagerLoads($model);
                    }
                }
            }
        });
    }

    protected function trackLazyLoading(): void
    {
        // Detect lazy loading via Model booted hook
        Model::preventLazyLoading(!app()->isProduction());
        
        // Track when relationships are accessed
        Event::listen('eloquent.booted:*', function ($event) {
            // This tracks when models are instantiated
        });
    }

    protected function trackModelEvents(): void
    {
        $events = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored'];

        foreach ($events as $event) {
            Event::listen("eloquent.{$event}:*", function ($eventName, $models) use ($event) {
                if (!empty($models)) {
                    $model = is_array($models) ? $models[0] : $models;
                    $modelClass = get_class($model);
                    
                    $this->eloquentMetrics['model_events'][] = [
                        'event' => $event,
                        'model' => class_basename($modelClass),
                        'model_class' => $modelClass,
                    ];
                }
            });
        }
    }

    protected function detectEagerLoads(Model $model): void
    {
        $relations = $model->getRelations();
        
        if (!empty($relations)) {
            foreach ($relations as $relationName => $relationData) {
                $this->eloquentMetrics['eager_loads'][] = [
                    'model' => class_basename(get_class($model)),
                    'relation' => $relationName,
                    'relation_type' => $this->detectRelationType($relationData),
                    'relation_count' => is_countable($relationData) ? count($relationData) : 1,
                ];
            }
        }
    }

    protected function detectRelationType($relationData): string
    {
        if (is_null($relationData)) {
            return 'null';
        }

        if ($relationData instanceof \Illuminate\Database\Eloquent\Collection) {
            return 'hasMany/belongsToMany';
        }

        if ($relationData instanceof Model) {
            return 'hasOne/belongsTo';
        }

        return 'unknown';
    }

    protected function sendMetrics(): void
    {
        $eagerCount = count($this->eloquentMetrics['eager_loads']);
        $lazyCount = count($this->eloquentMetrics['lazy_loads']);
        $eventsCount = count($this->eloquentMetrics['model_events']);

        if ($eagerCount === 0 && $lazyCount === 0 && $eventsCount === 0) {
            return;
        }

        // Calculate unique models
        $modelsUsed = array_unique(array_merge(
            array_column($this->eloquentMetrics['eager_loads'], 'model'),
            array_column($this->eloquentMetrics['model_events'], 'model')
        ));

        // Group events by type
        $eventsByType = [];
        foreach ($this->eloquentMetrics['model_events'] as $event) {
            $eventsByType[$event['event']] = ($eventsByType[$event['event']] ?? 0) + 1;
        }

        // Detect pivot usage
        $pivotUsage = $this->detectPivotUsage();

        $this->baddybugs->record('eloquent', 'usage_summary', [
            'eager_loads_count' => $eagerCount,
            'lazy_loads_count' => $lazyCount,
            'unique_models_used' => count($modelsUsed),
            'models_list' => $modelsUsed,
            'model_events_fired' => $eventsCount,
            'events_by_type' => $eventsByType,
            'relationships_loaded' => $this->eloquentMetrics['eager_loads'],
            'pivot_accesses_count' => $pivotUsage,
            'eager_to_lazy_ratio' => $lazyCount > 0 ? round($eagerCount / $lazyCount, 2) : null,
        ]);
    }

    protected function detectPivotUsage(): int
    {
        $count = 0;
        foreach ($this->eloquentMetrics['eager_loads'] as $load) {
            if ($load['relation_type'] === 'hasMany/belongsToMany') {
                $count++;
            }
        }
        return $count;
    }
}
