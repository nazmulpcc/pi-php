# Pi PHP

Pi PHP is a PHP implementation of the Pi agent runtime.
It gives you a stateful `Pi\Agent\Agent` façade, a lower-level `AgentLoop`, and a clean message/tool/event model for building AI-powered workflows in PHP.

## What lives here

- `packages/agent/` — the active agent runtime with tool calling, queues, events, and state management
- `packages/ai/` — the shared AI/provider layer used by the runtime
- `packages/coding-agent/` — higher-level coding-agent behavior built on top of the same foundations

## Install

```bash
composer install
```

## Quick start

The main entry point is `Pi\Agent\Agent`.
It manages prompt submission, continuation, steering, follow-up queues, cancellation, and event subscriptions.

```php
use Pi\Agent\Agent;

$agent = new Agent(
    streamFn: fn () => /* return your AI stream integration */ null,
);

$unsubscribe = $agent->subscribe(function ($event, $token) {
    // Observe lifecycle events here.
});

$agent->prompt('Hello world');
$agent->waitForIdle();

$unsubscribe();
```

## Core concepts

### Agent runtime

`Pi\Agent\Agent` is the user-facing façade.
It owns mutable state, queueing, cancellation, and listener management.

`Pi\Agent\AgentLoop` is the execution engine.
It streams model output, emits lifecycle events, runs tool calls, and appends messages back into state.

### Messages and content

- `UserMessage`
- `AssistantMessage`
- `ToolResultMessage`
- `CustomMessage`

Message content is modeled with small value objects like `TextContent`, `ImageContent`, `ThinkingContent`, and `ToolCall`.

### Tools and execution

Host applications provide tools by implementing `Pi\Agent\Tool\AgentTool`.
Tool execution can run in `sequential` or `parallel` mode via `ToolExecutionMode`.

### Events

The runtime emits lifecycle events for agent, turn, message, and tool phases.
Listen with `subscribe()` to observe streaming updates, tool execution, and completion.

## Development

```bash
composer test
composer lint
composer lint:check
./vendor/bin/pest packages/agent/tests
composer dump-autoload
```

## Testing

Tests live in `packages/agent/tests`, `packages/ai/tests`, and `packages/coding-agent/tests`.
The agent package tests cover prompt flow, queue semantics, cancellation, and event behavior.

## License

MIT
