<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user what happened to their account (warning, suspension, deactivation,
 * reactivation, closure, appeal decision): what, why, and what they can do next.
 * Stored in-app (Account Health page and the notification bell), emailed and pushed to the browser.
 */
class AccountStatusNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  warning | suspension | deactivation | reactivation | suspension_ended | closure | appeal_approved | appeal_rejected
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $message,
        public ?string $reason = null,
        public ?string $nextSteps = null,
        public ?int $violationId = null,
        public ?string $endsAt = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'reason' => $this->reason,
            'next_steps' => $this->nextSteps,
            'violation_id' => $this->violationId,
            'ends_at' => $this->endsAt,
            'link' => '/account/status',
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->message, 'url' => '/account/status'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('SOSOGON account notice: '.$this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message);

        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }
        if ($this->nextSteps) {
            $mail->line($this->nextSteps);
        }

        return $mail
            ->action('View your account status', rtrim((string) config('app.frontend_url'), '/').'/account/status')
            ->line('If you have questions, reply to the Sorsogon Tourism Office.');
    }
}
