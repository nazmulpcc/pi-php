# PI Agent Knowledgebase

> Comprehensive analysis of the PI agent monorepo at `pi-mono` for PHP reimplementation reference.

## 1. Project Overview

**Repository**: `badlogic/pi-mono`  
**License**: MIT  
**Language**: TypeScript (Node.js >=20)  
**Build Tool**: Custom `tsgo` (TypeScript compiler wrapper)

Pi is a coding agent CLI with a monorepo architecture. The CLI binary is `pi`, published as `@mariozechner/pi-coding-agent`.

### Monorepo Packages

| Package | Path | Purpose |
|---------|------|---------|
| `pi-ai` | `packages/ai` | Unified multi-provider LLM API |
| `pi-agent-core` | `packages/agent` | Agent runtime with tool calling and state management |
| `pi-coding-agent` | `packages/coding-agent` | Interactive coding agent CLI (the `pi` command) |
| `pi-tui` | `packages/tui` | Terminal UI library with differential rendering |
| `pi-web-ui` | `packages/web-ui` | Web components for AI chat interfaces |
| `pi-mom` | `packages/mom` | Slack bot delegating to pi coding agent |
| `pi-pods` | `packages/pods` | CLI for managing vLLM deployments on GPU pods |

---

## 2. Layered Architecture

The architecture is strictly layered bottom-up:

```
┌─────────────────────────────────────────┐
│  pi-coding-agent (CLI, modes, tools)    │
├─────────────────────────────────────────┤
│  pi-agent-core (Agent, AgentSession)    │
├─────────────────────────────────────────┤
│  pi-ai (LLM providers, streaming)       │
├─────────────────────────────────────────┤
│  pi-tui (Terminal UI framework)         │
└─────────────────────────────────────────┘
```

**Dependency Rule**: Upper layers depend on lower layers. No circular dependencies.

---

## 3. pi-ai: Unified LLM API

**Entry**: `packages/ai/src/index.ts`

### Core Abstractions

- **`Model<TApi>`**: Describes a model (id, name, provider, api, contextWindow, maxTokens, reasoning, cost, input types).
- **`Api`**: String union of supported APIs (`openai-completions`, `anthropic-messages`, `bedrock-converse-stream`, etc.).
- **`Context`**: `{ systemPrompt, messages: Message[], tools?: Tool[] }` — what gets sent to the LLM.
- **`Message`**: Union of `UserMessage`, `AssistantMessage`, `ToolResultMessage`.
- **`AssistantMessageEventStream`**: Async iterable of `AssistantMessageEvent` yielding partial updates.

### Provider Registration

Providers register lazily via `registerApiProvider()` in `packages/ai/src/api-registry.ts`:

```typescript
registerApiProvider({
  api: "anthropic-messages",
  stream: streamAnthropic,
  streamSimple: streamSimpleAnthropic,
});
```

Built-in providers are registered in `packages/ai/src/providers/register-builtins.ts`.

### Streaming Contract

`streamSimple(model, context, options)` returns an `AssistantMessageEventStream`.

**Events emitted** (in order):
- `start` — partial message placeholder
- `text_start`, `text_delta`, `text_end`
- `thinking_start`, `thinking_delta`, `thinking_end`
- `toolcall_start`, `toolcall_delta`, `toolcall_end`
- `done` — final message
- `error` — error message
- `usage` — token usage stats

**Critical contract**: The stream must never throw. Errors are encoded as `error` events with `stopReason: "error"`.

### Key Files

| File | Purpose |
|------|---------|
| `src/types.ts` | Core type definitions |
| `src/stream.ts` | `stream()`, `streamSimple()`, `complete()` |
| `src/api-registry.ts` | Provider registration map |
| `src/models.ts` / `src/models.generated.ts` | Model catalog |
| `src/providers/*.ts` | Provider implementations (Anthropic, OpenAI, Google, etc.) |
| `src/utils/event-stream.ts` | `EventStream<T, R>` generic async queue |

---

## 4. pi-agent-core: Agent Runtime

**Entry**: `packages/agent/src/index.ts`

### Two-Level Design

1. **Low-level**: `agentLoop()` / `agentLoopContinue()` — stateless functions.
2. **High-level**: `Agent` class — stateful wrapper with queues and event subscription.

### AgentLoop (`agent-loop.ts`)

The loop has a **nested while structure**:

```
Outer loop (while true):
  - Checks for follow-up messages after agent would naturally stop
  - If follow-ups exist, sets as pending and continues

  Inner loop (while hasMoreToolCalls || pendingMessages.length > 0):
    - Process pending steering messages (injected before next assistant turn)
    - Stream assistant response from LLM
    - Execute tool calls (sequential or parallel)
    - Collect tool results
    - Check if ALL tools returned terminate=true → stop inner loop
```

**Key functions**:
- `agentLoop(prompts, context, config, signal, streamFn)` — start with new user messages
- `agentLoopContinue(context, config, signal, streamFn)` — continue from existing transcript

### Agent Class (`agent.ts`)

Stateful wrapper around the loop:

```typescript
class Agent {
  state: AgentState;           // systemPrompt, model, tools, messages, isStreaming, etc.
  
  prompt(message): Promise<void>;     // Start new turn
  continue(): Promise<void>;          // Continue from last user/toolResult message
  steer(message): void;               // Queue steering message (interrupts current turn)
  followUp(message): void;            // Queue follow-up (runs after agent would stop)
  
  subscribe(listener): () => void;    // Listen to AgentEvent lifecycle events
  abort(): void;                      // Abort current run
  reset(): void;                      // Clear transcript and queues
}
```

**Queue modes**: `"all"` (drain all queued) or `"one-at-a-time"` (drain one per turn).

### AgentEvent Types

```typescript
type AgentEvent =
  | { type: "agent_start" }
  | { type: "agent_end"; messages: AgentMessage[] }
  | { type: "turn_start" }
  | { type: "turn_end"; message: AgentMessage; toolResults: ToolResultMessage[] }
  | { type: "message_start"; message: AgentMessage }
  | { type: "message_update"; message: AgentMessage; assistantMessageEvent: AssistantMessageEvent }
  | { type: "message_end"; message: AgentMessage }
  | { type: "tool_execution_start"; toolCallId: string; toolName: string; args: any }
  | { type: "tool_execution_update"; toolCallId: string; toolName: string; args: any; partialResult: any }
  | { type: "tool_execution_end"; toolCallId: string; toolName: string; result: any; isError: boolean };
```

### Tool Execution Flow

1. `prepareToolCall()` — validates tool exists, validates args with schema, runs `beforeToolCall` hook
2. `executePreparedToolCall()` — calls `tool.execute(id, params, signal, onUpdate)`
3. `finalizeExecutedToolCall()` — runs `afterToolCall` hook
4. `createToolResultMessage()` — builds `ToolResultMessage`
5. Emits `tool_execution_start` → `tool_execution_update` (streaming) → `tool_execution_end`

**Execution modes**:
- `"parallel"` (default): prepare sequentially, execute concurrently
- `"sequential"`: one at a time, or per-tool override via `tool.executionMode`

### Tool Definition

```typescript
interface AgentTool<TParameters extends TSchema = TSchema, TDetails = any> extends Tool<TParameters> {
  label: string;                                    // Human-readable name
  prepareArguments?: (args: unknown) => Static<TParameters>;
  execute: (toolCallId, params, signal?, onUpdate?) => Promise<AgentToolResult<TDetails>>;
  executionMode?: "sequential" | "parallel";
}

interface AgentToolResult<T> {
  content: (TextContent | ImageContent)[];
  details: T;
  terminate?: boolean;  // Hint: stop after this batch if ALL tools set true
}
```

### Key Files

| File | Purpose |
|------|---------|
| `src/agent-loop.ts` | Core loop logic |
| `src/agent.ts` | Stateful Agent class |
| `src/types.ts` | All type definitions |
| `src/proxy.ts` | Proxy utilities |

---

## 5. pi-coding-agent: The CLI

**Entry**: `packages/coding-agent/src/cli.ts` → `main.ts`

### Three Run Modes

1. **Interactive Mode** (`modes/interactive/interactive-mode.ts`)  
   - Full TUI with differential rendering  
   - Editor component, markdown rendering, tool execution UI  
   - Session picker, model selector, settings UI  
   - Keybindings, slash commands, extension widgets

2. **Print Mode** (`modes/print-mode.ts`)  
   - Single-shot: send prompt, output result, exit  
   - Supports `--mode text` (default) and `--mode json` (event stream)

3. **RPC Mode** (`modes/rpc/`)  
   - JSON-RPC over stdin/stdout  
   - For IDE/editor integrations

### CLI Argument Parsing

**No CLI library** (no yargs, commander, etc.). Manual flag processing in `cli/args.ts`:

```typescript
for (let i = 0; i < args.length; i++) {
  const arg = args[i];
  if (arg === "--help" || arg === "-h") {
    result.help = true;
  } else if (arg === "--model" && i + 1 < args.length) {
    result.model = args[++i];
  } // ... etc
}
```

### App Mode Resolution

```typescript
function resolveAppMode(parsed: Args, stdinIsTTY: boolean): AppMode {
  if (parsed.mode === "rpc") return "rpc";
  if (parsed.mode === "json") return "json";
  if (parsed.print || !stdinIsTTY) return "print";  // Batch mode (piped input)
  return "interactive";  // TUI mode
}
```

### Initialization Flow (`main.ts`)

1. Parse CLI args
2. Run migrations (`migrations.ts`)
3. Resolve session manager (create / resume / fork / select)
4. Create `AgentSessionRuntime` with services:
   - `AuthStorage`
   - `SettingsManager`
   - `ModelRegistry`
   - `ResourceLoader`
5. Resolve model (CLI → scoped → saved default → first available)
6. Build initial message (process `@file` arguments, stdin piped content)
7. Launch mode (interactive / print / rpc)

---

## 6. AgentSession (`core/agent-session.ts`)

The bridge between the generic `Agent` and the coding-agent-specific features.

### Responsibilities

- **Event subscription** with automatic session persistence
- **Model management** (set, cycle, scoped models via Ctrl+P)
- **Thinking level management** (off, minimal, low, medium, high, xhigh)
- **Compaction** (manual and auto) — summarize old context to fit window
- **Retry logic** — auto-retry on rate limits / server errors
- **Bash execution** — with operations abstraction
- **Session switching / branching / forking**
- **Extension integration** — `ExtensionRunner` with event hooks

### Prompt Flow

```typescript
async prompt(text: string, options?: PromptOptions): Promise<void>
```

1. Check for extension slash commands (`/command`) — execute immediately
2. Emit `input` event to extensions (can transform or handle)
3. Expand skill commands (`/skill:name`) and prompt templates
4. If streaming: queue via `steer()` or `followUp()`
5. Validate model and API key
6. Check compaction before sending
7. Build user message + pending "nextTurn" messages
8. Emit `before_agent_start` to extensions (can modify system prompt)
9. Call `agent.prompt(messages)`
10. Wait for retry if auto-retry initiated

### Session Persistence

Uses `SessionManager` which writes to a **JSONL file** (`.pi/sessions/<id>.jsonl`).

Entry types in session file:
- `message` — user, assistant, or toolResult messages
- `model_change` — model switch events
- `thinking_level_change` — thinking level changes
- `compaction` — context compaction summary
- `branch_summary` — branch navigation summary
- `custom` / `custom_message` — extension data
- `label` — user bookmarks
- `session_info` — display name

### Compaction (`core/compaction/`)

When context window is near full:
1. `prepareCompaction()` — identify which entries to summarize
2. `compact()` — send old messages to LLM for summary generation
3. `sessionManager.appendCompaction()` — save summary entry
4. Replace old messages in agent state with summary

Triggered by:
- **Manual**: `/compact` command
- **Threshold**: context usage exceeds configured percentage
- **Overflow**: LLM returns context overflow error

---

## 7. Built-in Tools

**Location**: `packages/coding-agent/src/core/tools/`

| Tool | File | Purpose |
|------|------|---------|
| `read` | `read.ts` | Read file contents with truncation |
| `bash` | `bash.ts` | Execute shell commands with timeout, streaming output |
| `edit` | `edit.ts` | Edit files via search/replace or diff |
| `write` | `write.ts` | Write new files |
| `grep` | `grep.ts` | Search file contents (regex) |
| `find` | `find.ts` | Find files by pattern |
| `ls` | `ls.ts` | List directory contents |

### Tool Factory Pattern

```typescript
// Definition (schema + metadata)
export function createReadToolDefinition(cwd: string, options?: ReadToolOptions): ToolDefinition {
  return {
    name: "read",
    description: "Read a file...",
    parameters: readSchema,  // TypeBox schema
  };
}

// Runtime tool (schema + execute)
export function createReadTool(cwd: string, options?: ReadToolOptions): AgentTool {
  return {
    name: "read",
    label: "Read",
    description: "Read a file...",
    parameters: readSchema,
    execute: async (toolCallId, params, signal, onUpdate) => {
      // ... read file, truncate, return result
      return { content: [{ type: "text", text: content }], details: { path } };
    },
  };
}
```

### Default Active Tools

Default: `["read", "bash", "edit", "write"]`  
Read-only set: `["read", "grep", "find", "ls"]`  
All tools: `read`, `bash`, `edit`, `write`, `grep`, `find`, `ls`

---

## 8. Extension System

**Location**: `packages/coding-agent/src/core/extensions/`

Extensions are TypeScript modules that can:
- Subscribe to agent lifecycle events
- Register LLM-callable tools
- Register slash commands, keyboard shortcuts, CLI flags
- Interact with user via UI primitives

### ExtensionRunner (`extensions/runner.ts`)

Central event dispatcher. Extensions register handlers for event types:

```typescript
// Event types extensions can handle
"agent_start" | "agent_end" | "turn_start" | "turn_end"
| "message_start" | "message_update" | "message_end"
| "tool_call" | "tool_result" | "tool_execution_start" | "tool_execution_end"
| "input" | "before_agent_start" | "after_provider_response"
| "session_before_compact" | "session_compact" | "session_before_tree"
| "model_select" | "context"
```

### ExtensionAPI

Extensions receive an `ExtensionContext` (`pi` object) with methods:
- `sendMessage(text)` — send user message
- `getSession()` — access session state
- `registerCommand(name, handler)` — slash commands
- `registerTool(definition, handler)` — custom tools
- `registerAutocomplete(provider)` — autocomplete
- `on(event, handler)` — event subscription
- UI methods: `select()`, `confirm()`, `input()`, `notify()`, `setStatus()`, etc.

---

## 9. Configuration & Settings

### Two-Tier Settings (`core/settings-manager.ts`)

- **Global**: `~/.pi/agent/settings.json`
- **Project**: `./.pi/settings.json` (overrides global)

File locking with `proper-lockfile` for safe concurrent access.

### Auth Storage (`core/auth-storage.ts`)

`~/.pi/agent/auth.json`:
```json
{
  "anthropic": { "type": "api_key", "key": "sk-..." },
  "github-copilot": { "type": "oauth", "accessToken": "...", "refreshToken": "..." }
}
```

### Environment Variables

| Variable | Purpose |
|----------|---------|
| `PI_OFFLINE` | Skip network checks |
| `PI_CODING_AGENT_DIR` | Override config dir (`~/.pi/agent`) |
| `PI_SKIP_VERSION_CHECK` | Skip version check |
| `PI_STARTUP_BENCHMARK` | Benchmark startup time |

---

## 10. Session Manager (`core/session-manager.ts`)

Manages JSONL session files.

### Key Methods

```typescript
SessionManager.create(cwd, sessionDir?)     // New session
SessionManager.open(path, sessionDir?)      // Open existing
SessionManager.continueRecent(cwd, sessionDir?) // Continue most recent
SessionManager.forkFrom(sourcePath, cwd, sessionDir?) // Fork session
SessionManager.list(cwd, sessionDir?)       // List sessions
SessionManager.listAll()                    // List all sessions globally

// Instance methods
appendMessage(message)
appendModelChange(provider, modelId)
appendThinkingLevelChange(level)
appendCompaction(summary, firstKeptEntryId, tokensBefore, details, fromExtension?)
buildSessionContext() → { messages, thinkingLevel, model }
getBranch() → SessionEntry[]     // Current branch entries
getTree() → SessionTreeNode[]    // Full tree with forks
```

---

## 11. TUI Framework (`pi-tui`)

**Location**: `packages/tui/src/`

Custom terminal UI with **differential rendering** (only redraws changed lines).

### Core Classes

- **`TUI`** — root container, manages render loop
- **`ProcessTerminal`** — raw stdin/stdout handling, Kitty keyboard protocol
- **`Container`** — layout container
- **`Editor`** — text editor component
- **`Markdown`** — markdown renderer
- **`Text`**, **`Spacer`**, **`Loader`** — basic components

### Key Features

- Raw mode stdin with `process.stdin.setRawMode(true)`
- Kitty keyboard protocol for rich key events
- Async render loop: `requestRender()` → diff → output changed lines
- Component tree with focus management

---

## 12. Key Patterns for PHP Implementation

### 1. Event-Driven Architecture

Use an event emitter / async event stream. PHP can use ReactPHP or Amphp for async, or a simpler generator-based event stream.

### 2. Layered Design

Keep the same layering:
- **AI layer**: Provider abstraction, streaming, models
- **Agent layer**: Loop, state, tool execution
- **Application layer**: CLI, session, tools, UI

### 3. Agent Loop Pattern

The nested while loop is the heart. Implement as:
```php
while (true) {
    while ($hasMoreToolCalls || count($pendingMessages) > 0) {
        // Stream assistant response
        // Execute tools
        // Check steering messages
    }
    // Check follow-up messages
    if (empty($followUpMessages)) break;
    $pendingMessages = $followUpMessages;
}
```

### 4. Tool System

Each tool needs:
- Name, description, label
- JSON Schema parameters (use `opis/json-schema` or similar)
- `execute(string $toolCallId, array $params, ?Cancellation $cancellation, ?callable $onUpdate): ToolResult`

### 5. Session Persistence

JSONL is simple and language-agnostic. Each line is a JSON object with a `type` field.

### 6. Queue System

Steering and follow-up queues with modes:
- `all`: drain everything at once
- `one-at-a-time`: drain one message per turn

### 7. Configuration

Two-tier JSON config (global + project-local) with file locking.

### 8. Streaming Contract

The LLM stream must never throw. Always return events. Final result via `stream->result()`.

### 9. Abort/Cancellation

Pass `AbortSignal` (or PHP `CancellationToken`) through every async boundary. Tools must honor cancellation.

### 10. Extension Hooks

Before/after tool call hooks allow interception:
- `beforeToolCall`: can block execution
- `afterToolCall`: can modify result content/details/error flag

---

## 13. File Index (Critical Files)

### AI Layer
- `packages/ai/src/types.ts`
- `packages/ai/src/stream.ts`
- `packages/ai/src/api-registry.ts`
- `packages/ai/src/utils/event-stream.ts`

### Agent Core
- `packages/agent/src/agent-loop.ts`
- `packages/agent/src/agent.ts`
- `packages/agent/src/types.ts`

### Coding Agent - CLI
- `packages/coding-agent/src/cli.ts`
- `packages/coding-agent/src/main.ts`
- `packages/coding-agent/src/cli/args.ts`

### Coding Agent - Core
- `packages/coding-agent/src/core/agent-session.ts`
- `packages/coding-agent/src/core/sdk.ts`
- `packages/coding-agent/src/core/agent-session-services.ts`
- `packages/coding-agent/src/core/session-manager.ts`
- `packages/coding-agent/src/core/settings-manager.ts`
- `packages/coding-agent/src/core/auth-storage.ts`
- `packages/coding-agent/src/core/system-prompt.ts`

### Coding Agent - Tools
- `packages/coding-agent/src/core/tools/index.ts`
- `packages/coding-agent/src/core/tools/bash.ts`
- `packages/coding-agent/src/core/tools/read.ts`
- `packages/coding-agent/src/core/tools/edit.ts`
- `packages/coding-agent/src/core/tools/write.ts`

### Coding Agent - Modes
- `packages/coding-agent/src/modes/print-mode.ts`
- `packages/coding-agent/src/modes/interactive/interactive-mode.ts`

### Coding Agent - Extensions
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

*Generated from exhaustive exploration of `pi-mono` source code.*
