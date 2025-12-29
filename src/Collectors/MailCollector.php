<?php

namespace BaddyBugs\Agent\Collectors;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use BaddyBugs\Agent\Facades\BaddyBugs;
use Symfony\Component\Mime\Address;

class MailCollector implements CollectorInterface
{
    public function boot(): void
    {
        Event::listen(MessageSent::class, function (MessageSent $event) {
            $this->collect($event);
        });
    }

    protected function collect(MessageSent $event): void
    {
        if (BaddyBugs::shouldFilterMail($event)) {
            return;
        }

        $message = $event->message;

        $payload = [
            'subject' => $message->getSubject(),
            'to' => $this->formatRecipients($message->getTo()),
            'cc' => $this->formatRecipients($message->getCc()),
            'bcc' => $this->formatRecipients($message->getBcc()),
            'from' => $this->formatRecipients($message->getFrom()),
            'has_attachments' => count($message->getAttachments()) > 0,
        ];
        
        // Try to identify Mailable class if available in data
        if (isset($event->data['__laravel_notification'])) {
            $payload['notification'] = get_class($event->data['__laravel_notification']);
        }

        BaddyBugs::record('mail', $message->getSubject() ?: 'No Subject', $payload);
    }

    protected function formatRecipients(array $recipients): array
    {
        return array_map(function ($recipient) {
            if ($recipient instanceof Address) {
                return $recipient->getAddress();
            }
            return (string) $recipient;
        }, $recipients);
    }
}

