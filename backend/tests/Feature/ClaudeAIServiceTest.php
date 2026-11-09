<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Contracts\ItineraryAI;
use App\Exceptions\AIServiceUnavailableException;
use App\Services\ClaudeAIService;
use App\Services\GeminiAIService;
use App\Services\ItineraryService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * ClaudeAIService against a fake HTTP transporter: Guzzle's MockHandler answers every request
 * from a queue and never opens a connection, and the history middleware records what would
 * have been sent. Only a placeholder key is used.
 */
class ClaudeAIServiceTest extends TestCase
{
    private const PROMPT = "You are a local travel itinerary planner.\nRESPOND WITH ONLY THIS JSON.";

    private const ITINERARY = '{"title":"Bulan Beach Day","days":[{"day_number":1,"destinations":[{"destination_id":7,"reason":"Calm water for a relaxed pace."}]}]}';

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    public function test_successful_response_returns_the_json_text(): void
    {
        $text = $this->service([$this->message(self::ITINERARY)])->generate(self::PROMPT);

        $this->assertSame(self::ITINERARY, $text);
        $this->assertCount(1, $this->sent);
    }

    public function test_request_uses_the_model_prompt_and_strict_itinerary_schema(): void
    {
        $this->service([$this->message(self::ITINERARY)])->generate(self::PROMPT);

        $request = $this->sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame('api.anthropic.com', $request->getUri()->getHost());
        $this->assertSame('/v1/messages', $request->getUri()->getPath());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));

        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(16000, $body['max_tokens']);
        $this->assertSame([['role' => 'user', 'content' => self::PROMPT]], $body['messages']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('effort', $body['output_config']);

        $format = $body['output_config']['format'];
        $this->assertSame('json_schema', $format['type']);
        $schema = $format['schema'];
        $this->assertSame(['title', 'days'], $schema['required']);
        $day = $schema['properties']['days']['items'];
        $this->assertSame(['day_number', 'destinations'], $day['required']);
        $this->assertSame('integer', $day['properties']['day_number']['type']);
        $entry = $day['properties']['destinations']['items'];
        $this->assertSame(['destination_id'], $entry['required']);
        $this->assertSame(['destination_id', 'reason'], array_keys($entry['properties']));
        $this->assertSchemaIsStrict($schema);
    }

    public function test_configured_effort_is_sent(): void
    {
        $this->service([$this->message(self::ITINERARY)], effort: 'medium')->generate(self::PROMPT);

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame('medium', $body['output_config']['effort']);
    }

    public function test_invalid_effort_fails_before_any_request(): void
    {
        $this->expectExceptionMessage('Invalid ANTHROPIC_EFFORT "fast"');

        try {
            $this->service([], effort: 'fast')->generate(self::PROMPT);
        } finally {
            $this->assertCount(0, $this->sent);
        }
    }

    public function test_refusal_is_an_error_not_an_itinerary(): void
    {
        $this->expectExceptionMessage('AI declined to plan this itinerary.');

        $this->service([$this->message('I cannot help with that.', 'refusal')])->generate(self::PROMPT);
    }

    public function test_max_tokens_response_is_an_error(): void
    {
        $this->expectExceptionMessage('AI response was cut off before it finished.');

        $this->service([$this->message('{"title":"Bulan', 'max_tokens')])->generate(self::PROMPT);
    }

    public function test_rate_limit_means_busy(): void
    {
        $this->assertBusy([$this->error(429, 'rate_limit_error', 'Number of request tokens has exceeded your per-minute rate limit')]);
    }

    public function test_overloaded_529_means_busy(): void
    {
        $this->assertBusy([$this->error(529, 'overloaded_error', 'Overloaded')]);
    }

    public function test_server_error_500_means_busy(): void
    {
        $this->assertBusy([$this->error(500, 'api_error', 'Internal server error')]);
    }

    public function test_timeout_means_busy(): void
    {
        $timeout = new ConnectException(
            'cURL error 28: Operation timed out after 120001 milliseconds',
            new Request('POST', 'https://api.anthropic.com/v1/messages'),
        );

        $this->assertBusy([$timeout]);
    }

    public function test_retries_stop_at_the_configured_limit(): void
    {
        $busy = fn () => $this->error(429, 'rate_limit_error', 'Rate limited', ['retry-after-ms' => '1']);

        $text = $this->service([$busy(), $this->message(self::ITINERARY)], maxRetries: 1)->generate(self::PROMPT);
        $this->assertSame(self::ITINERARY, $text);
        $this->assertCount(2, $this->sent);

        $this->sent = [];
        try {
            $this->service([$busy(), $busy(), $this->message(self::ITINERARY)], maxRetries: 1)->generate(self::PROMPT);
            $this->fail('Expected the AI to be reported busy.');
        } catch (AIServiceUnavailableException $e) {
            $this->assertFalse($e->quotaExhausted);
        }
        $this->assertCount(2, $this->sent);
    }

    public function test_low_credit_balance_is_a_billing_error(): void
    {
        $lowBalance = $this->error(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.');
        $billing = $this->error(402, 'billing_error', 'Payment required.');

        foreach ([$lowBalance, $billing] as $response) {
            try {
                $this->service([$response])->generate(self::PROMPT);
                $this->fail('Expected a billing error.');
            } catch (AIServiceUnavailableException $e) {
                $this->assertTrue($e->quotaExhausted);
                $this->assertStringContainsString('credits are exhausted (billing)', $e->getMessage());
            }
        }
    }

    public function test_invalid_key_is_a_plain_error(): void
    {
        try {
            $this->service([$this->error(401, 'authentication_error', 'invalid x-api-key')])->generate(self::PROMPT);
            $this->fail('Expected an error.');
        } catch (\Exception $e) {
            $this->assertNotInstanceOf(AIServiceUnavailableException::class, $e);
            $this->assertSame('AI service error. Status: 401', $e->getMessage());
        }
    }

    public function test_missing_key_fails_before_any_request(): void
    {
        $this->expectExceptionMessage('Claude API key is not configured. Set ANTHROPIC_API_KEY.');

        try {
            $this->service([], apiKey: '')->generate(self::PROMPT);
        } finally {
            $this->assertCount(0, $this->sent);
        }
    }

    public function test_gemini_stays_the_default_and_claude_is_never_built(): void
    {
        $this->assertSame('gemini', config('services.itinerary.ai_provider'));
        $this->assertInstanceOf(GeminiAIService::class, $this->app->make(ItineraryAI::class));
        $this->assertFalse($this->app->resolved(ClaudeAIService::class));
    }

    public function test_claude_provider_resolves_through_the_container(): void
    {
        config(['services.itinerary.ai_provider' => 'claude']);
        $fake = $this->service([$this->message(self::ITINERARY)]);
        $this->app->instance(ClaudeAIService::class, $fake);

        $this->assertSame($fake, $this->app->make(ItineraryAI::class));
        $this->assertInstanceOf(ItineraryService::class, $this->app->make(ItineraryService::class));
        $this->assertSame(self::ITINERARY, $this->app->make(ItineraryAI::class)->generate(self::PROMPT));
        $this->assertCount(1, $this->sent);
    }

    public function test_container_built_service_without_a_key_never_calls_the_api(): void
    {
        config([
            'services.itinerary.ai_provider' => 'claude',
            'services.anthropic.api_key' => null,
        ]);

        $service = $this->app->make(ItineraryAI::class);

        $this->assertInstanceOf(ClaudeAIService::class, $service);
        $this->expectExceptionMessage('Claude API key is not configured.');
        $service->generate(self::PROMPT);
    }

    /** A ClaudeAIService whose HTTP transporter only answers from $responses. */
    private function service(array $responses, int $maxRetries = 0, string $apiKey = 'test-key', ?string $effort = null): ClaudeAIService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $client = new Client(
            apiKey: $apiKey,
            requestOptions: RequestOptions::with(maxRetries: $maxRetries, transporter: new GuzzleClient(['handler' => $stack])),
            openTelemetry: false,
        );

        return new ClaudeAIService($client, 'claude-sonnet-5-5', $effort);
    }

    private function message(string $text, string $stopReason = 'end_turn'): Response
    {
        return new Response(200, ['content-type' => 'application/json'], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ]));
    }

    private function error(int $status, string $type, string $message, array $headers = []): Response
    {
        return new Response($status, ['content-type' => 'application/json'] + $headers, json_encode([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ]));
    }

    private function assertBusy(array $responses): void
    {
        try {
            $this->service($responses)->generate(self::PROMPT);
            $this->fail('Expected the AI to be reported busy.');
        } catch (AIServiceUnavailableException $e) {
            $this->assertFalse($e->quotaExhausted);
        }
        $this->assertCount(1, $this->sent);
    }

    /** Every type is lowercase and every object is closed with additionalProperties: false. */
    private function assertSchemaIsStrict(array $schema): void
    {
        $this->assertSame(strtolower($schema['type']), $schema['type']);
        if ($schema['type'] === 'object') {
            $this->assertFalse($schema['additionalProperties']);
            foreach ($schema['properties'] as $property) {
                $this->assertSchemaIsStrict($property);
            }
        }
        if ($schema['type'] === 'array') {
            $this->assertSchemaIsStrict($schema['items']);
        }
    }
}
