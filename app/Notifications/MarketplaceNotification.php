<?php

namespace App\Notifications;

use App\Services\Settings\MarketplaceSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

class MarketplaceNotification extends Notification
{
    /** @param array<string,mixed> $payload */
    public function __construct(string $id, private array $payload)
    {
        $this->id = $id;
    }

    /** @return array<string,mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('app.url'), '/');
        if (! preg_match('#^https?://[^/]+#', $base) || ! NotificationAction::safe($this->payload['action_path'] ?? '')) {
            throw new \LogicException('A safe application URL is required.');
        }

        $settings = app(MarketplaceSettings::class);
        $message = (new MailMessage)->from((string) config('mail.from.address'), app(MarketplaceSettings::class)->sender(str_starts_with($this->payload['type'] ?? '', 'support')))->subject($this->payload['title'])->greeting('Hello,')
            ->line($this->payload['message'])->action('View in Templaara', $base.$this->payload['action_path'])
            ->line('Sign in to your Templaara account to view the details.')
            ->withSymfonyMessage(function (Email $mail): void {
                $mail->getHeaders()->addIdHeader('Message-ID', $this->id.'@templaara-notifications');
            });
        if ($settings->value('notifications', 'show_support_contact') && ($contact = $settings->identity()['support_contact_email'])) {
            $message->line('Support: '.$contact);
        }

        return $message;
    }
}
