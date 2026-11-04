<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\ErrorType;
use Anthropic\Messages\OutputConfig\Effort;
use App\Contracts\ItineraryAI;
use App\Exceptions\AIServiceUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * Itinerary AI backed by Anthropic's Claude (Messages API). The client is injected, so its
 * key, HTTP timeout and retry limit come from whoever builds it (AppServiceProvider, or a
 * test with a fake transporter).
 */
class ClaudeAIService implements ItineraryAI
{
    // Room for Claude's reasoning plus the itinerary JSON.
    private const MAX_TOKENS = 16000;

    // If Claude declines a request for policy reasons, the API retries it on a fallback model
    // inside the same call.
    private const REFUSAL_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private Client $client,
        private string $model,
        private ?string $effort = null,
    ) {}

    /**
     * @throws AIServiceUnavailableException when Claude is busy, rate-limited, unreachable or out of credits
     */
    public function generate(string $prompt): string
    {
        if ($this->client->apiKey === '') {
            throw new \RuntimeException('Claude API key is not configured. Set ANTHROPIC_API_KEY.');
        }

        $effort = null;
        if ($this->effort !== null && $this->effort !== '') {
            $effort = Effort::tryFrom($this->effort)
                ?? throw new \RuntimeException("Invalid ANTHROPIC_EFFORT \"{$this->effort}\". Use low, medium, high, xhigh or max.");
        }

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $this->model,
                fallbacks: 'default',
                outputConfig: array_filter([
                    'effort' => $effort?->value,
                    'format' => ['type' => 'json_schema', 'schema' => $this->itinerarySchema()],
                ]),
                betas: [self::REFUSAL_FALLBACK_BETA],
            );
        } catch (RateLimitException|InternalServerException $e) {
            Log::warning('Claude API busy', ['model' => $this->model, 'status' => $e->status, 'type' => $e->type?->value]);

            throw new AIServiceUnavailableException('Claude is busy or rate-limited.');
        } catch (APIConnectionException $e) {
            // Timeouts arrive here too: the HTTP client gives up and reports a network error.
            Log::warning('Claude API unreachable', ['model' => $this->model, 'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);

            throw new AIServiceUnavailableException('Claude could not be reached or timed out.');
        } catch (APIStatusException $e) {
            if ($this->isOutOfCredits($e)) {
                Log::error('Claude API credit balance is too low; add credits in the Claude Console', ['model' => $this->model, 'status' => $e->status]);

                throw new AIServiceUnavailableException('Claude API credits are exhausted (billing).', true);
            }

            Log::error('Claude API Error', ['model' => $this->model, 'status' => $e->status, 'body' => $e->body]);

            throw new \Exception('AI service error. Status: '.$e->status);
        }

        if ($message->stopReason === 'refusal') {
            Log::warning('Claude declined the itinerary request', ['model' => $message->model]);

            throw new \Exception('AI declined to plan this itinerary.');
        }

        if ($message->stopReason === 'max_tokens') {
            Log::warning('Claude itinerary response hit the token limit', ['model' => $message->model]);

            throw new \Exception('AI response was cut off before it finished.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new \Exception('Unexpected AI response format.');
    }

    /** A billing error, or the 400 the API sends when the credit balance is too low. */
    private function isOutOfCredits(APIStatusException $e): bool
    {
        $message = is_array($e->body) ? (string) ($e->body['error']['message'] ?? '') : '';

        return $e->type === ErrorType::BILLING_ERROR || stripos($message, 'credit balance') !== false;
    }

    /**
     * The same shape as GeminiAIService's schema (and ItineraryService::buildPrompt()), in
     * standard JSON Schema: lowercase types, and every object closed with
     * additionalProperties: false as Claude's structured outputs require.
     */
    private function itinerarySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'days' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'day_number' => ['type' => 'integer'],
                            'destinations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'destination_id' => ['type' => 'integer'],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['destination_id'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['day_number', 'destinations'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'days'],
            'additionalProperties' => false,
        ];
    }
}
