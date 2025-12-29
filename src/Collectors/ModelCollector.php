<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Breadcrumbs;

class ModelCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen('eloquent.created: *', [$this, 'handleCreated']);
        Event::listen('eloquent.updated: *', [$this, 'handleUpdated']);
        Event::listen('eloquent.deleted: *', [$this, 'handleDeleted']);
        Event::listen('eloquent.restored: *', [$this, 'handleRestored']);
    }

    public function handleCreated(string $event, array $data): void
    {
        $this->record('created', $event, $data);
    }

    public function handleUpdated(string $event, array $data): void
    {
        $this->record('updated', $event, $data);
    }

    public function handleDeleted(string $event, array $data): void
    {
        $this->record('deleted', $event, $data);
    }

    public function handleRestored(string $event, array $data): void
    {
        $this->record('restored', $event, $data);
    }

    protected function record(string $action, string $event, array $data): void
    {
        $model = $data[0] ?? null;
        
        if (!$model instanceof Model) {
            return;
        }

        $modelClass = get_class($model);
        $key = $model->getKey();
        
        // Add to breadcrumbs
        Breadcrumbs::add('model', "{$action} {$modelClass}", [
            'id' => $key,
            'action' => $action,
        ]);

        // Only record full details if model tracking is enabled
        if (!config('baddybugs.collectors.models_detailed', false)) {
            return;
        }

        $payload = [
            'action' => $action,
            'model' => $modelClass,
            'key' => $key,
            'table' => $model->getTable(),
        ];

        // For updates, capture dirty fields
        if ($action === 'updated') {
            $payload['changes'] = array_keys($model->getDirty());
        }

        BaddyBugs::record('model', $modelClass, $payload);
    }
}
