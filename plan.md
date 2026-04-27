# Plan: Take `packages/ai` to Production-Ready Runtime Parity

## Summary

Target runtime parity with `pi-mono/packages/ai`, not full package parity. The PHP package should become a production-capable LLM runtime for real model calls, with the same core contracts, equivalent provider behavior where practical, and a staged provider rollout that fully hardens the top-tier providers first.

Chosen defaults:
- Runtime/library parity, not CLI/OAuth parity in this initiative
- Top-tier providers first: OpenAI Responses, OpenAI Completions, Anthropic, Azure OpenAI Responses
- Env-var / explicit API-key auth first; OAuth/browser login stays out of scope unless a provider is otherwise impossible to support

## Implementation Changes

### 1. Finish the core contract so provider parity is possible
- Expand the PHP type surface to cover the remaining upstream runtime fields, especially provider compatibility and routing options used by OpenAI-compatible, Anthropic-compatible, and gateway-style providers.
- Bring agent-facing bridge types up to parity with AI runtime types where metadata must survive round-trips. This includes preserving provider-specific tool-call and content signatures, not only thinking signatures.
- Add an env-key helper equivalent to upstream `env-api-keys.ts` so provider implementations can resolve known credential env vars consistently instead of duplicating lookup logic.
- Formalize provider registration so built-ins can be enabled by provider family and tested independently, instead of relying on a single always-on registration path.

Public/interface changes:
- Extend `StreamOptions` / `SimpleStreamOptions` with the remaining runtime-relevant fields from upstream that affect provider requests, retries, caching, headers, metadata, routing, and compatibility behavior.
- Extend content/message types where parity requires persisted metadata across turns.
- Keep `stream()`, `complete()`, `streamSimple()`, and `completeSimple()` as the stable public entrypoints.

### 2. Build a reusable HTTP and streaming transport layer for real calls
- Introduce a shared HTTP transport abstraction used by all real providers. It should support normal JSON requests, SSE streams, chunked streaming, request timeouts, headers, retries, cancellation, and uniform error shaping.
- Keep React promises as the package boundary, but do not force each provider to hand-roll cURL logic. Centralize request execution, SSE parsing, retry policy, and cancellation adaptation.
- Add provider-agnostic helpers for:
  - streaming frame parsing
  - structured provider error extraction
  - request/response inspection hooks
  - compatibility-driven payload shaping
  - prompt caching flags and retention handling
- Preserve the current event-stream contract: start, deltas, terminal done/error, and final `result()` resolution.

Decision:
- Use one shared internal transport implementation for all providers rather than provider-local HTTP logic.
- The transport should be private/internal to `packages/ai`; providers expose only runtime types and promises.

### 3. Reach production readiness for the top-tier providers
- Harden OpenAI Responses from “works” to “production-ready”:
  - real HTTP streaming path
  - cancellation and timeout behavior
  - retries and error mapping
  - response/tool/reasoning metadata preservation
  - compatibility with session caching and usage accounting
- Port OpenAI Completions next:
  - chat-completions style streaming
  - compatibility matrix for OpenAI-compatible providers
  - reasoning/thinking option mapping
  - tool schema conversion and usage extraction
  - URL/provider capability overrides analogous to upstream compat handling
- Port Anthropic Messages:
  - Claude thinking modes
  - Anthropic cache control conventions
  - tool call streaming and eager input streaming support
  - long-cache retention semantics where supported
  - provider-specific message transformation requirements
- Port Azure OpenAI Responses as a thin specialization over the OpenAI Responses machinery:
  - Azure auth/header/base URL differences
  - Azure model/API registration and built-in provider registration
  - Azure-specific payload or capability differences only where necessary

Public/interface changes:
- Add provider-specific options classes for OpenAI Completions, Anthropic, and Azure OpenAI Responses, following the same pattern already used for OpenAI Responses.
- Add any compatibility option objects required for OpenAI-compatible providers to avoid encoding those rules ad hoc in model definitions.

### 4. Add the long-tail providers in a controlled second wave
- After top-tier providers are green, port the remaining upstream runtime providers in this order:
  1. OpenAI Codex Responses
  2. Mistral
  3. Google
  4. Google Vertex
  5. Amazon Bedrock
- Treat Google Gemini CLI and OAuth-backed flows as separate follow-on milestones, not blockers for runtime parity.
- For each provider family, implement only the runtime behavior that belongs in `packages/ai`; do not mix in CLI login or browser auth flows in this initiative.

Decision:
- “On par” for this project means the runtime provider matrix is completed in phases, but OAuth/CLI parity is explicitly deferred.

### 5. Complete model catalog and built-in registration parity
- Replace the current partial generated catalog with a generator pipeline that derives PHP catalog data from upstream source or a normalized intermediate manifest, so model updates are reproducible.
- Expand provider and API enums/constants to cover the upstream runtime matrix.
- Register built-in providers by API family, not just the currently implemented provider.
- Ensure `Models`, `supportsXhigh()`, cost calculation, provider lists, and model equality work across the full generated catalog, not only the current subset.

Decision:
- The model catalog must be generated, not curated manually, before declaring parity.

### 6. Close the remaining transformation and validation gaps
- Bring `TransformMessages` to full behavioral parity for all supported runtime providers:
  - same-model preservation
  - cross-model downgrades
  - thought-signature handling
  - tool-call ID normalization
  - orphaned tool-result synthesis
  - provider-specific replay quirks
- Expand validation to the schema shapes actually used by upstream providers and tool definitions, including coercion, enums, arrays, object nesting, optional fields, and readable error messages.
- Ensure image/tool-result replay behavior matches upstream semantics across providers.

Decision:
- Validation stays package-owned; do not defer provider-specific schema correctness to the agent layer.

## Test Plan

### Provider-independent tests
- Add parity-style tests for all runtime types and metadata round-trips, including `textSignature`, `thinkingSignature`, `thoughtSignature`, `responseId`, redaction flags, and usage fields.
- Expand event-stream tests to cover delayed async events, cancellation mid-stream, retries after transient failures, and terminal error propagation.
- Add env-key resolution tests for all supported providers with API-key auth.
- Add model-catalog tests that verify generated-provider coverage and drift detection against the source manifest.

### Provider behavior tests
- OpenAI Responses:
  - real SSE frame parsing
  - reasoning block replay
  - tool-call partial JSON handling
  - cache/session behavior
  - response ID and usage propagation
  - cancellation, timeout, and retry behavior
- OpenAI Completions:
  - streaming deltas
  - usage extraction
  - compatibility overrides
  - reasoning option mapping
  - tool call conversion
- Anthropic:
  - thinking display modes
  - tool streaming
  - cache control markers
  - long cache retention
  - stop-reason mapping
- Azure:
  - auth/header/base URL differences
  - streaming and error behavior matching OpenAI Responses expectations

### Acceptance criteria
- `packages/agent` can make real streamed calls through `packages/ai` using at least OpenAI Responses, OpenAI Completions, Anthropic, and Azure without custom integration code.
- All top-tier providers support tool calls, streaming events, cancellation, timeouts, usage accounting, and provider-specific metadata preservation.
- The generated model catalog covers the targeted runtime providers and is reproducible from source.
- The full `packages/ai` and `packages/agent` test suites pass with dedicated provider integration fixtures and no manual file edits to keep the model list in sync.

## Assumptions

- OAuth/browser login and CLI parity are explicitly out of scope for this initiative.
- Real model calls means production-capable API-key/env-var based calls first, not local mock parity.
- Provider rollout is staged, but the plan is not complete until top-tier provider parity is done and the remaining runtime providers are sequenced with the shared transport and catalog machinery already in place.
- `packages/agent` remains a consumer of `packages/ai`; no provider logic should leak upward into the agent package.

