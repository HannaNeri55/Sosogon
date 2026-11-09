<?php

namespace App\Services;

use App\Contracts\ItineraryAI;
use App\Exceptions\AIServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAIService implements ItineraryAI
{
    private string $apiKey;

    /** @var string[] Main model first, then fallbacks in order. */
    private array $models;

    // Only transient server/network errors are retried on the same model. Overload (503)
    // and rate limits (429) move straight to the next model: the fallback chain is the retry,
    // and every extra request eats into the free tier's small per-model daily quota.
    private const MAX_ATTEMPTS_PER_MODEL = 2;

    private const RETRY_SAME_MODEL_STATUSES = [500];

    private const NEXT_MODEL_STATUSES = [429, 503];

    // Model missing or retired for this key: no point retrying, go straight to the next one.
    private const SKIP_MODEL_STATUSES = [404];

    /** How long an overloaded model is skipped before it is tried again. */
    private const BUSY_COOLDOWN_SECONDS = 60;

    /** Google resets free-tier daily quotas at midnight Pacific time. */
    private const QUOTA_TIMEZONE = 'America/Los_Angeles';

    private const UNAVAILABLE_BUSY = 'busy';

    private const UNAVAILABLE_QUOTA = 'quota';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
        $this->models = array_values(array_unique([
            config('services.gemini.model', 'gemini-3.8-flash'),
            ...config('services.gemini.fallback_models', []),
        ]));
    }

    /**
     * Tries the main model, then each fallback, skipping models recently seen overloaded or
     * out of daily quota. Any other API error (e.g. a bad request) fails immediately.
     *
     * @throws AIServiceUnavailableException when every model is busy or unavailable
     */
    public function generate(string $prompt): string
    {
        $tried = 0;

        foreach ($this->models as $index => $model) {
            if (Cache::has($this->unavailableKey($model))) {
                continue;
            }

            $tried++;
            $text = $this->generateWithModel($model, $prompt);

            if ($text !== null) {
                if ($index > 0) {
                    Log::warning('Gemini fallback model used', ['model' => $model, 'primary' => $this->models[0]]);
                }

                return $text;
            }
        }

        $quotaExhausted = collect($this->models)
            ->every(fn ($model) => Cache::get($this->unavailableKey($model)) === self::UNAVAILABLE_QUOTA);

        Log::warning('All Gemini models unavailable', ['tried' => $tried, 'quota_exhausted' => $quotaExhausted]);

        throw new AIServiceUnavailableException('All AI models are busy or unavailable.', $quotaExhausted);
    }

    /** Returns the response text, or null if this model is busy/unavailable and the next one should be tried. */
    private function generateWithModel(string $model, string $prompt): ?string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS_PER_MODEL; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'content-type' => 'application/json',
                ])->timeout(90)->post($url, [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 8192,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $this->itinerarySchema(),
                    ],
                ]);
            } catch (ConnectionException $e) {
                Log::error('Gemini connection error', ['model' => $model, 'attempt' => $attempt, 'error' => $e->getMessage()]);

                if ($attempt < self::MAX_ATTEMPTS_PER_MODEL) {
                    $this->sleepBeforeRetry($attempt);
                }

                continue;
            }

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    return $data['candidates'][0]['content']['parts'][0]['text'];
                }

                throw new \Exception('Unexpected AI response format.');
            }

            Log::error('Gemini API Error', [
                'model' => $model,
                'status' => $response->status(),
                'body' => $response->body(),
                'attempt' => $attempt,
            ]);

            if (in_array($response->status(), self::SKIP_MODEL_STATUSES, true)) {
                return null;
            }

            if (in_array($response->status(), self::NEXT_MODEL_STATUSES, true)) {
                $this->markUnavailable($model, $response);

                return null;
            }

            if (! in_array($response->status(), self::RETRY_SAME_MODEL_STATUSES, true)) {
                throw new \Exception('AI service error. Status: '.$response->status());
            }

            if ($attempt < self::MAX_ATTEMPTS_PER_MODEL) {
                $this->sleepBeforeRetry($attempt);
            }
        }

        return null;
    }

    /**
     * Remembers that a model can't serve right now so later requests skip it instead of
     * spending quota: until Google's daily reset when its daily quota is used up, for the
     * API's suggested delay on a per-minute limit, or briefly when it is overloaded.
     */
    private function markUnavailable(string $model, Response $response): void
    {
        $details = $response->json('error.details') ?? [];
        $violations = collect($details)->firstWhere('@type', 'type.googleapis.com/google.rpc.QuotaFailure')['violations'] ?? [];
        $dailyQuota = collect($violations)->contains(fn ($v) => str_contains($v['quotaId'] ?? '', 'PerDay'));

        if ($response->status() === 429 && $dailyQuota) {
            $until = now(self::QUOTA_TIMEZONE)->addDay()->startOfDay();
            Cache::put($this->unavailableKey($model), self::UNAVAILABLE_QUOTA, $until);
            Log::warning('Gemini daily quota exhausted', ['model' => $model, 'until' => $until->toIso8601String()]);

            return;
        }

        $retryDelay = collect($details)->firstWhere('@type', 'type.googleapis.com/google.rpc.RetryInfo')['retryDelay'] ?? null;
        $seconds = $retryDelay ? (int) ceil((float) rtrim($retryDelay, 's')) : self::BUSY_COOLDOWN_SECONDS;

        Cache::put($this->unavailableKey($model), self::UNAVAILABLE_BUSY, now()->addSeconds(max($seconds, 1)));
    }

    private function unavailableKey(string $model): string
    {
        return "gemini-unavailable:{$model}";
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        usleep((int) (min(2 ** $attempt, 8) * 500_000));
    }

    /** Matches the JSON shape requested in ItineraryService::buildPrompt(). */
    private function itinerarySchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => ['type' => 'STRING'],
                // One or two destinations per day (an empty list on free days). Activities,
                // meals, shops and the stay are picked by ItineraryService, not the AI.
                'days' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'day_number' => ['type' => 'INTEGER'],
                            'destinations' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'destination_id' => ['type' => 'INTEGER'],
                                        'reason' => ['type' => 'STRING'],
                                    ],
                                    'required' => ['destination_id'],
                                ],
                            ],
                        ],
                        'required' => ['day_number', 'destinations'],
                    ],
                ],
            ],
            'required' => ['title', 'days'],
        ];
    }
}
