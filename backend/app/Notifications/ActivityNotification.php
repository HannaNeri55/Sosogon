<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Notification;

/**
 * Something a user should know about right away (a registration or submission waiting for the
 * admin, a decision on an owner's listing, a new review, a reply): who did what, and the page
 * to open. Shown in the notification bell and pushed to the user's browsers.
 */
class ActivityNotification extends Notification
{
    /**
     * @param  string  $link  site path to open, e.g. /admin/submissions
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $message,
        public ?string $link = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'link' => $this->link,
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->message, 'url' => $this->link ?? '/'];
    }
}
