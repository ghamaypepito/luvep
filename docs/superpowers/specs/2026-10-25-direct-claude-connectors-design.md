# Direct Claude connectors

User-approved direction: Luvep backend connects directly to Claude for Pathy and EP Assistant; no n8n in either chat. User explicitly excluded Social/Marketing on 2026-10-25.

## Scope and approach
Use the existing Laravel/React application rather than a new service or n8n. Add a shared Anthropic Messages API transport and a Super Admin connector screen. Preserve staff authorization, conversation ownership, read-only record context, AI kill switches, and database contents. Do not switch unrelated assessment/ad-generation integrations.

The user supplies the API key through a password field on the connector screen or ANTHROPIC_API_KEY on the server. Store a UI-supplied key encrypted using Laravel's application key. Return only configured/not-configured; never return the key, flash it in validation responses, audit it, or expose it in generic Settings. Editable separate instructions supplement fixed safety and scope rules. Models are configurable per assistant. Both assistants start disabled until the administrator connects and enables them. Saving configuration performs no paid API call; an explicit test reads model metadata without generating a response.

Pathy sends a bounded user/assistant history to the existing /api/chat endpoint. No private records, documents, or new private-data access. Deterministic escalation for visa eligibility/advice and distress precedes model invocation. Published program context only; no fabricated fees. Staff chat uses its existing ownership checks and record context. Resumed record conversations recheck access before retrieval.

Default model is configurable (claude-sonnet-5-5 per current Anthropic model docs); direct API URL is fixed to https://api.anthropic.com/v1/messages, bearer auth, anthropic-version 2026-10-21. Sonnet 5.5 uses between_tools thinking mode for concise text responses. Bound timeouts and tokens; sanitize provider failures. Explicit UI disclosure explains the destination of prompts/context. No live provider verification is possible until the user supplies a key.

## Operations
Deploy configuration UI and code without copying .env or overwriting unrelated production files. Preserve the earlier zero-cost visibility patch. No subscriptions are cancelled. GitHub source synchronization remains separately unauthorized from the previous task.
