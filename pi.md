# PI Agent Knowledgebase

> Reference notes for the `pi-mono` TypeScript monorepo.

## 1. Project Overview

**Repository**: `badlogic/pi-mono`  
**License**: MIT  
**Language**: TypeScript (Node.js >=20)  
**Build Tool**: Custom `tsgo` wrapper

Pi is a coding agent monorepo. The main CLI is `pi`, published as `@mariozechner/pi-coding-agent`.

### Monorepo Packages

| Package | Path | Purpose |
|---------|------|---------|
| `pi-ai` | `packages/ai` | Unified multi-provider LLM API |
| `pi-agent-core` | `packages/agent` | Agent runtime with tool calling and state management |
| `pi-coding-agent` | `packages/coding-agent` | Interactive coding agent CLI |
| `pi-tui` | `packages/tui` | Terminal UI library with differential rendering |
| `pi-web-ui` | `packages/web-ui` | Web components for AI chat interfaces |
| `pi-mom` | `packages/mom` | Slack bot delegating to the coding agent |
| `pi-pods` | `packages/pods` | CLI for managing vLLM deployments on GPU pods |

---

## 2. Layered Architecture

```text
pi-coding-agent (CLI, modes, tools)
pi-agent-core   (agent loop, state, tool execution)
pi-ai           (LLM providers, streaming)
pi-tui          (terminal UI framework)
```

Rules:
- upper layers depend on lower layers
- no circular dependencies
- `pi-ai` is the provider/runtime foundation

---

## 3. pi-ai: Unified LLM API

**Entry**: `packages/ai/src/index.ts`

### Core Abstractions
- `Model<TApi>`: model descriptor
- `Api`: provider API family
- `Context`: `{ systemPrompt, messages, tools? }`
- `Message`: `UserMessage | AssistantMessage | ToolResultMessage`
- `AssistantMessageEventStream`: async iterable of assistant events

### Provider Registration
Providers register via `registerApiProvider()` in `packages/ai/src/api-registry.ts`.
Built-ins are registered in `packages/ai/src/providers/register-builtins.ts`.

### Streaming Contract
`streamSimple(model, context, options)` returns an `AssistantMessageEventStream`.

Events include:
- `start`
- `text_start`, `text_delta`, `text_end`
- `thinking_start`, `thinking_delta`, `thinking_end`
- `toolcall_start`, `toolcall_delta`, `toolcall_end`
- `done`
- `error`
- `usage`

Critical rule: the stream should not throw; errors are emitted as `error` events.

### Key Files
- `src/types.ts`
- `src/stream.ts`
- `src/api-registry.ts`
- `src/models.ts` / `src/models.generated.ts`
- `src/providers/*.ts`
- `src/utils/event-stream.ts`

---

## 4. pi-agent-core: Agent Runtime

**Entry**: `packages/agent/src/index.ts`

### Two-Level Design
1. low-level: `agentLoop()` / `agentLoopContinue()`
2. high-level: `Agent` class with queues and event subscription

### Agent Loop
The loop is nested:
- outer loop checks follow-up messages
- inner loop handles steering messages, assistant streaming, tool calls, and tool results

Key functions:
- `agentLoop(prompts, context, config, signal, streamFn)`
- `agentLoopContinue(context, config, signal, streamFn)`

### Agent Class
Core methods:
- `prompt(message)`
- `continue()`
- `steer(message)`
- `followUp(message)`
- `subscribe(listener)`
- `abort()`
- `reset()`

Queue modes:
- `all`
- `one-at-a-time`

### Agent Events
- `agent_start`
- `agent_end`
- `turn_start`
- `turn_end`
- `message_start`
- `message_update`
- `message_end`
- `tool_execution_start`
- `tool_execution_update`
- `tool_execution_end`

### Tool Execution Flow
1. `prepareToolCall()`
2. `executePreparedToolCall()`
3. `finalizeExecutedToolCall()`
4. `createToolResultMessage()`

Execution modes:
- `parallel`
- `sequential`

### Tool Definition
- `label`
- `prepareArguments?`
- `execute(...)`
- `executionMode?`

---

## 5. pi-coding-agent: CLI

**Entry**: `packages/coding-agent/src/cli.ts` → `main.ts`

### Run Modes
1. **Interactive**: full TUI
2. **Print**: single-shot prompt/response
3. **RPC**: JSON-RPC over stdin/stdout

### CLI Parsing
Arguments are parsed manually in `cli/args.ts`.

### App Mode Resolution
- `rpc` if requested
- `json` if requested
- `print` if piped input or `--print`
- otherwise `interactive`

### Initialization Flow
1. parse args
2. run migrations
3. resolve session manager
4. create runtime services
5. resolve model
6. build initial message
7. launch selected mode

---

## 6. AgentSession

**Entry**: `packages/coding-agent/src/core/agent-session.ts`

Responsibilities:
- event subscription and persistence
- model management
- thinking level management
- compaction
- retry logic
- bash execution
- session switching / branching / forking
- extension integration

### Session Persistence
Sessions are stored as JSONL under `.pi/sessions/<id>.jsonl`.

Entry types:
- `message`
- `model_change`
- `thinking_level_change`
- `compaction`
- `branch_summary`
- `custom`
- `custom_message`
- `label`
- `session_info`

### Compaction
Triggered manually or when context gets too large.
Steps:
1. prepare compaction
2. summarize old messages
3. append compaction entry
4. replace old context with summary

---

## 7. Built-in Tools

Location: `packages/coding-agent/src/core/tools/`

| Tool | Purpose |
|------|---------|
| `read` | Read file contents with truncation |
| `bash` | Execute shell commands with timeout and streaming output |
| `edit` | Search/replace or diff-based file editing |
| `write` | Write new files |
| `grep` | Search file contents |
| `find` | Find files by pattern |
| `ls` | List directory contents |

Default active tools:
- `read`
- `bash`
- `edit`
- `write`

Read-only set:
- `read`
- `grep`
- `find`
- `ls`

---

## 8. Extension System

Location: `packages/coding-agent/src/core/extensions/`

Extensions can:
- subscribe to agent events
- register tools
- register commands
- register autocomplete
- use UI primitives

Core API surface:
- `sendMessage(text)`
- `getSession()`
- `registerCommand(name, handler)`
- `registerTool(definition, handler)`
- `on(event, handler)`
- `select()`, `confirm()`, `input()`, `notify()`, `setStatus()`

---

## 9. Configuration & Settings

### Settings
- global: `~/.pi/agent/settings.json`
- project: `./.pi/settings.json`

### Auth Storage
`~/.pi/agent/auth.json`

### Environment Variables
- `PI_OFFLINE`
- `PI_CODING_AGENT_DIR`
- `PI_SKIP_VERSION_CHECK`
- `PI_STARTUP_BENCHMARK`

---

## 10. Session Manager

Location: `packages/coding-agent/src/core/session-manager.ts`

Key methods:
- `create()`
- `open()`
- `continueRecent()`
- `forkFrom()`
- `list()`
- `listAll()`

Instance methods:
- `appendMessage()`
- `appendModelChange()`
- `appendThinkingLevelChange()`
- `appendCompaction()`
- `buildSessionContext()`
- `getBranch()`
- `getTree()`

---

## 11. TUI Framework

Location: `packages/tui/src/`

Core classes:
- `TUI`
- `ProcessTerminal`
- `Container`
- `Editor`
- `Markdown`
- `Text`
- `Spacer`
- `Loader`

Features:
- raw mode stdin
- Kitty keyboard protocol
- differential rendering
- focus-managed component tree

---

## 12. Key Patterns

- event-driven architecture
- layered design
- nested agent loop
- tool system with schemas
- JSONL session persistence
- steering and follow-up queues
- streaming contract with no thrown provider errors
- cancellation through every async boundary
- extension hooks before and after tool calls

---

## 13. Critical Files

### AI Layer
- `packages/ai/src/types.ts`
- `packages/ai/src/stream.ts`
- `packages/ai/src/api-registry.ts`
- `packages/ai/src/utils/event-stream.ts`

### Agent Core
- `packages/agent/src/agent-loop.ts`
- `packages/agent/src/agent.ts`
- `packages/agent/src/types.ts`

### CLI
- `packages/coding-agent/src/cli.ts`
- `packages/coding-agent/src/main.ts`
- `packages/coding-agent/src/cli/args.ts`

### Core
- `packages/coding-agent/src/core/agent-session.ts`
- `packages/coding-agent/src/core/sdk.ts`
- `packages/coding-agent/src/core/session-manager.ts`
- `packages/coding-agent/src/core/settings-manager.ts`
- `packages/coding-agent/src/core/auth-storage.ts`
- `packages/coding-agent/src/core/system-prompt.ts`

### Tools
- `packages/coding-agent/src/core/tools/index.ts`
- `packages/coding-agent/src/core/tools/bash.ts`
- `packages/coding-agent/src/core/tools/read.ts`
- `packages/coding-agent/src/core/tools/edit.ts`
- `packages/coding-agent/src/core/tools/write.ts`

### Modes
- `packages/coding-agent/src/modes/print-mode.ts`
- `packages/coding-agent/src/modes/interactive/interactive-mode.ts`

### Extensions
- `packages/coding-agent/src/core/extensions/types.ts`
- `packages/coding-agent/src/core/extensions/runner.ts`

### TUI
- `packages/tui/src/tui.ts`
- `packages/tui/src/terminal.ts`
- `packages/tui/src/index.ts`

---

## 14. Dependencies Summary

| Layer | Key Dependencies |
|-------|-----------------|
| AI | `openai`, `@anthropic-ai/sdk`, `@google/genai`, `@mistralai/mistralai`, `@aws-sdk/client-bedrock-runtime`, `typebox`, `undici` |
| Agent | `pi-ai`, `typebox` |
| Coding Agent | `pi-agent-core`, `pi-ai`, `pi-tui`, `chalk`, `marked`, `diff`, `glob`, `uuid`, `yaml`, `undici` |
| TUI | `chalk`, `marked`, `mime-types`, `get-east-asian-width` |

---

*Reference notes for `pi-mono`.*
