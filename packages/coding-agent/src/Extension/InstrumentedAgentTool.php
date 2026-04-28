<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentTool;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Schema;
use Pi\AI\Support\PromiseHelper;
use React\Promise\PromiseInterface;

final readonly class InstrumentedAgentTool implements AgentTool
{
    public function __construct(
        private AgentTool $inner,
        private ExtensionRunner $runner,
    ) {}

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getLabel(): string
    {
        return $this->inner->getLabel();
    }

    public function getDescription(): string
    {
        return $this->inner->getDescription();
    }

    public function getParameters(): array|Schema
    {
        return $this->inner->getParameters();
    }

    public function getExecutionMode(): ToolExecutionMode
    {
        return $this->inner->getExecutionMode();
    }

    public function prepareArguments(array $args): array
    {
        return $this->inner->prepareArguments($args);
    }

    public function execute(
        string $toolCallId,
        array $params,
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): PromiseInterface {
        $toolName = $this->getName();
        $toolCallEvent = $this->runner->emitToolCall([
            'type' => 'tool_call',
            'toolCallId' => $toolCallId,
            'toolName' => $toolName,
            'args' => $params,
        ]);

        if (is_array($toolCallEvent) && (($toolCallEvent['block'] ?? false) === true)) {
            $reason = (string) ($toolCallEvent['reason'] ?? 'Tool execution was blocked by an extension.');

            return PromiseHelper::resolve(new AgentToolResult([new TextContent($reason)], ['blocked' => true], false))
                ->then(function (AgentToolResult $result) use ($toolCallId, $toolName) {
                    $toolResultEvent = $this->runner->emitToolResult([
                        'type' => 'tool_result',
                        'toolCallId' => $toolCallId,
                        'toolName' => $toolName,
                        'result' => $result,
                        'isError' => true,
                    ]);

                    if (is_array($toolResultEvent)) {
                        return new AgentToolResult(
                            $toolResultEvent['content'] ?? $result->content,
                            $toolResultEvent['details'] ?? $result->details,
                            $toolResultEvent['terminate'] ?? $result->terminate,
                        );
                    }

                    return $result;
                });
        }

        return PromiseHelper::resolve($this->inner->execute($toolCallId, $params, $signal, $onUpdate))
            ->then(function (AgentToolResult $result) use ($toolCallId, $toolName) {
                $toolResultEvent = $this->runner->emitToolResult([
                    'type' => 'tool_result',
                    'toolCallId' => $toolCallId,
                    'toolName' => $toolName,
                    'result' => $result,
                    'isError' => false,
                ]);

                if (is_array($toolResultEvent)) {
                    return new AgentToolResult(
                        $toolResultEvent['content'] ?? $result->content,
                        $toolResultEvent['details'] ?? $result->details,
                        $toolResultEvent['terminate'] ?? $result->terminate,
                    );
                }

                return $result;
            }, function (\Throwable $error) use ($toolCallId, $toolName) {
                $message = $error->getMessage();
                $toolResult = new AgentToolResult([new TextContent($message)]);
                $toolResultEvent = $this->runner->emitToolResult([
                    'type' => 'tool_result',
                    'toolCallId' => $toolCallId,
                    'toolName' => $toolName,
                    'result' => $toolResult,
                    'isError' => true,
                ]);

                if (is_array($toolResultEvent)) {
                    $message = implode(' ', array_map(
                        static fn (mixed $item): string => is_array($item) && ($item['type'] ?? null) === 'text'
                            ? (string) ($item['text'] ?? '')
                            : (is_object($item) && property_exists($item, 'text') ? (string) $item->text : ''),
                        $toolResultEvent['content'] ?? [],
                    ));
                }

                throw new \RuntimeException($message, 0, $error);
            });
    }
}
