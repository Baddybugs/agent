<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use BaddyBugs\Agent\Breadcrumbs;

class NotificationCollector implements CollectorInterface
{
    protected array $pendingNotifications = [];

    public function boot(): void
    {
        Event::listen(NotificationSending::class, [$this, 'handleSending']);
        Event::listen(NotificationSent::class, [$this, 'handleSent']);
        Event::listen(NotificationFailed::class, [$this, 'handleFailed']);
    }

    public function handleSending(NotificationSending $event): void
    {
        if (BaddyBugs::shouldRejectNotification($event->notification, $event->channel)) {
            return;
        }

        $hash = $this->getHash($event);
        $this->pendingNotifications[$hash] = microtime(true);
    }

    public function handleSent(NotificationSent $event): void
    {
        $hash = $this->getHash($event);
        $start = $this->pendingNotifications[$hash] ?? microtime(true);
        $duration = (microtime(true) - $start) * 1000;
        unset($this->pendingNotifications[$hash]);

        $notificationClass = get_class($event->notification);
        
        Breadcrumbs::add('notification', "Sent {$notificationClass} via {$event->channel}", [
            'channel' => $event->channel,
        ]);

        BaddyBugs::record('notification', $notificationClass, [
            'channel' => $event->channel,
            'notifiable' => $this->formatNotifiable($event->notifiable),
            'duration_ms' => $duration,
            'status' => 'sent',
        ]);
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $hash = $this->getHash($event);
        $start = $this->pendingNotifications[$hash] ?? microtime(true);
        $duration = (microtime(true) - $start) * 1000;
        unset($this->pendingNotifications[$hash]);

        $notificationClass = get_class($event->notification);

        Breadcrumbs::add('notification', "Failed {$notificationClass} via {$event->channel}", [
            'channel' => $event->channel,
        ], 'error');

        BaddyBugs::record('notification', $notificationClass, [
            'channel' => $event->channel,
            'notifiable' => $this->formatNotifiable($event->notifiable),
            'duration_ms' => $duration,
            'status' => 'failed',
            'error' => $event->data['exception'] ?? null,
        ]);
    }

    protected function getHash($event): string
    {
        return spl_object_hash($event->notification) . $event->channel;
    }

    protected function formatNotifiable($notifiable): array
    {
        if (method_exists($notifiable, 'getKey')) {
            return [
                'type' => get_class($notifiable),
                'id' => $notifiable->getKey(),
            ];
        }
        return ['type' => get_class($notifiable)];
    }
}
