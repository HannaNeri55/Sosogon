<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's notification bell: recent notifications, the unread count (checked every
 * few seconds by the site), marking them read, and signing a browser up for push notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'notifications' => $user->notifications()->latest()->limit(30)->get(['id', 'type', 'data', 'read_at', 'created_at'])
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'kind' => $n->data['kind'] ?? null,
                    'title' => $n->data['title'] ?? 'Notification',
                    'message' => $n->data['message'] ?? '',
                    'link' => $n->data['link'] ?? null,
                    'read_at' => $n->read_at?->toIso8601String(),
                    'created_at' => $n->created_at?->toIso8601String(),
                ]),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($notification)->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /** The public key a browser needs to sign up; null when push isn't set up on this server. */
    public function pushKey(): JsonResponse
    {
        return response()->json(['public_key' => config('services.webpush.public_key') ?: null]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:500', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        // One row per browser; signing in as someone else on the same browser moves it to them.
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            ],
        );

        return response()->json(['message' => 'Push notifications are on for this browser.']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))->delete();

        return response()->json(['message' => 'Push notifications are off for this browser.']);
    }
}
