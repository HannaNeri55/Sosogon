<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Sends a notification's toWebPush() payload to every browser the user signed up for push
 * notifications, so it shows on their computer or phone even with the site closed.
 * Subscriptions the browser has since dropped (404/410) are deleted.
 */
class WebPushChannel
{
    /** @param  ClientInterface|null  $client  the HTTP client that reaches the push services (tests pass a fake) */
    public function __construct(private ?ClientInterface $client = null) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $config = config('services.webpush');
        if (empty($config['public_key']) || empty($config['private_key']) || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $push = new WebPush(['VAPID' => [
            'subject' => $config['subject'],
            'publicKey' => $config['public_key'],
            'privateKey' => $config['private_key'],
        ]], ['TTL' => 86400, 'urgency' => 'normal'], $this->client ?? new GuzzleClient(['timeout' => 5]));

        $payload = json_encode($notification->toWebPush($notifiable));
        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $payload);
        }

        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', PushSubscription::hashFor($report->getEndpoint()))->delete();
            } elseif (! $report->isSuccess()) {
                Log::warning('Push notification not delivered: '.$report->getReason());
            }
        }
    }
}
