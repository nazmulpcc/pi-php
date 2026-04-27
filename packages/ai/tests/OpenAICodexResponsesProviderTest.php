<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';
require_once aiPackageRoot('src/OpenAI/OpenAIResponsesShared.php');
require_once aiPackageRoot('src/OpenAI/Codex/CodexWebSocketTransport.php');
require_once aiPackageRoot('src/OpenAI/Codex/OpenAICodexResponsesOptions.php');
require_once aiPackageRoot('src/OpenAI/Codex/OpenAICodexResponsesProvider.php');

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Pi\AI\Api;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\OpenAI\Codex\CodexWebSocketTransport;
use Pi\AI\OpenAI\Codex\OpenAICodexResponsesOptions;
use Pi\AI\OpenAI\Codex\OpenAICodexResponsesProvider;
use Pi\AI\Provider;
use Pi\AI\Schema\Type;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;
use Pi\AI\Transport;
use Pi\AI\UsageCost;
use React\EventLoop\Loop;

use function React\Promise\resolve;

final class FakeCodexSocket implements EventEmitterInterface
{
    use EventEmitterTrait;

    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    /** @var list<string> */
    public array $sent = [];

    public function __construct(array $batches)
    {
        $this->batches = $batches;
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
        $batch = array_shift($this->batches) ?? [];

        Loop::futureTick(function () use ($batch): void {
            foreach ($batch as $event) {
                $this->emit('message', [json_encode($event, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function close(): void
    {
        $this->emit('close', [1000, 'closed']);
    }
}

function createCodexProviderModel(): Model
{
    return new Model(
        id: 'gpt-5.1-codex-mini',
        name: 'GPT-5.1 Codex Mini',
        api: new Api(Api::OPENAI_CODEX_RESPONSES),
        provider: new Provider(Provider::OPENAI_CODEX),
        baseUrl: 'https://chatgpt.com/backend-api',
        reasoning: true,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 272000,
        maxTokens: 128000,
    );
}

function codexJwt(string $accountId): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'https://api.openai.com/auth' => ['chatgpt_account_id' => $accountId],
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $header.'.'.$payload.'.signature';
}

describe('OpenAI Codex responses provider', function () {
    afterEach(function () {
        CodexWebSocketTransport::setConnectorFactoryForTests(null);
    });

    it('builds a stream from a fake transport and preserves response id', function () {
        $provider = new OpenAICodexResponsesProvider(
            transport: static function (Model $model, Context $context, $options, array $params): iterable {
                expect($model)->toBeInstanceOf(Model::class);
                expect($context)->toBeInstanceOf(Context::class);
                expect($options)->toBeInstanceOf(OpenAICodexResponsesOptions::class);
                expect($params['model'])->toBe('gpt-5.1-codex-mini');
                expect($params['store'])->toBeFalse();
                expect($params['stream'])->toBeTrue();
                expect($params['tool_choice'])->toBe('auto');
                expect($params['parallel_tool_calls'])->toBeTrue();
                expect($params['prompt_cache_key'])->toBe('session-1');
                expect($params['text']['verbosity'])->toBe('low');
                expect($params['include'])->toBe(['reasoning.encrypted_content']);
                expect($params['reasoning'])->toBe([
                    'effort' => 'medium',
                    'summary' => 'auto',
                ]);
                expect($params['tools'][0]['name'])->toBe('echo');

                return [
                    ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
                    ['type' => 'response.output_item.added', 'item' => ['type' => 'message']],
                    ['type' => 'response.output_text.delta', 'delta' => 'hello'],
                    ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [['type' => 'output_text', 'text' => 'hello']]]],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'total_tokens' => 25, 'input_tokens_details' => ['cached_tokens' => 0]]]],
                ];
            },
        );

        $stream = $provider->stream(createCodexProviderModel(), new Context(
            messages: [new UserMessage('hi', time())],
            systemPrompt: 'Be concise.',
            tools: [new Tool('echo', 'Echo text', Type::object(['text' => Type::string()]))],
        ), new OpenAICodexResponsesOptions(
            apiKey: codexJwt('acct_123'),
            sessionId: 'session-1',
            reasoningEffort: 'medium',
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        $terminal = $events[array_key_last($events)];
        expect($terminal)->toBeInstanceOf(DoneEvent::class);
        expect($terminal->message->responseId)->toBe('resp_1');
        expect($terminal->message->content[0]->text)->toBe('hello');
    });

    it('maps simple reasoning options and extracts the chatgpt account id', function () {
        $provider = new OpenAICodexResponsesProvider(
            transport: static function (Model $model, Context $context, $options, array $params): iterable {
                expect($model)->toBeInstanceOf(Model::class);
                expect($context)->toBeInstanceOf(Context::class);
                expect($options)->toBeInstanceOf(OpenAICodexResponsesOptions::class);
                expect($params['reasoning']['effort'])->toBe('high');
                expect($params['text']['verbosity'])->toBe('low');

                return [
                    ['type' => 'response.output_item.added', 'item' => ['type' => 'message']],
                    ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_2', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_2', 'status' => 'completed', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2, 'input_tokens_details' => ['cached_tokens' => 0]]]],
                ];
            },
        );

        $stream = $provider->streamSimple(createCodexProviderModel(), new Context(
            messages: [new UserMessage('hi', time())],
        ), new SimpleStreamOptions(
            reasoning: ThinkingLevel::Xhigh,
        ));

        $message = block($stream->result());
        expect($message->stopReason)->toBe(StopReason::Stop);

        $method = new ReflectionMethod($provider, 'extractAccountId');

        expect($method->invoke($provider, codexJwt('acct_456')))->toBe('acct_456');
    });

    it('supports websocket transport and reuses cached sessions', function () {
        $connections = [];
        CodexWebSocketTransport::setConnectorFactoryForTests(function () use (&$connections) {
            $socket = new FakeCodexSocket([
                [
                    ['type' => 'response.created', 'response' => ['id' => 'resp_ws_1']],
                    ['type' => 'response.output_item.added', 'item' => ['type' => 'message']],
                    ['type' => 'response.output_text.delta', 'delta' => 'hello'],
                    ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_ws_1', 'content' => [['type' => 'output_text', 'text' => 'hello']]]],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_ws_1', 'status' => 'completed', 'usage' => ['input_tokens' => 3, 'output_tokens' => 1, 'total_tokens' => 4, 'input_tokens_details' => ['cached_tokens' => 0]]]],
                ],
                [
                    ['type' => 'response.created', 'response' => ['id' => 'resp_ws_2']],
                    ['type' => 'response.output_item.added', 'item' => ['type' => 'message']],
                    ['type' => 'response.output_text.delta', 'delta' => 'again'],
                    ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_ws_2', 'content' => [['type' => 'output_text', 'text' => 'again']]]],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_ws_2', 'status' => 'completed', 'usage' => ['input_tokens' => 2, 'output_tokens' => 1, 'total_tokens' => 3, 'input_tokens_details' => ['cached_tokens' => 0]]]],
                ],
            ]);
            $connections[] = $socket;

            return resolve($socket);
        });

        $provider = new OpenAICodexResponsesProvider;
        $options = new OpenAICodexResponsesOptions(
            apiKey: codexJwt('acct_ws'),
            transport: Transport::Websocket,
            sessionId: 'session-ws',
        );

        $streamOne = $provider->stream(createCodexProviderModel(), new Context(messages: [new UserMessage('one', time())]), $options);
        $messageOne = block($streamOne->result());

        $streamTwo = $provider->stream(createCodexProviderModel(), new Context(messages: [new UserMessage('two', time())]), $options);
        $messageTwo = block($streamTwo->result());

        expect(count($connections))->toBe(1);
        expect($messageOne->responseId)->toBe('resp_ws_1');
        expect($messageTwo->responseId)->toBe('resp_ws_2');
        expect($connections[0]->sent)->toHaveCount(2);
    });
});
