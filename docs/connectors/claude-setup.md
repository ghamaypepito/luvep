# Configure Claude for Pathy and EP Assistant

Super Admin: open `/admin/ai-connectors` (AI Connectors in the sidebar).

1. Paste your Anthropic API key into the password field. Do not put it in chat, GitHub, or frontend code.
2. Choose a model ID available to your Anthropic API account for each assistant. The default is `claude-sonnet-5-5`. Other models can have different thinking/token requirements.
3. Review the separate instructions. Mandatory scope, read-only and no-advice rules are applied by the server in addition to your text.
4. Save. The form clears the key field and only reports whether a key is configured.
5. Test each connection. Testing reads model metadata; it does not generate a paid response. Successful authentication/model lookup is not an end-to-end generation test.
6. Enable Pathy and/or EP Assistant, then save. If global AI is off, enable the existing global setting as well.
7. Send a harmless greeting in each chat to verify actual generation. This uses your Anthropic API credit.

You can also configure `ANTHROPIC_API_KEY` and `ANTHROPIC_MODEL` in the server environment, then rebuild Laravel's config cache. A key saved in the UI takes precedence. Removing the saved key can reveal an existing environment key; turn both assistants off to stop generation regardless of key source.

## Scope

- Pathy calls `/api/chat` on Luvep's own origin, never the old n8n webhook. Requests are bounded and throttled. Context comes only from published program data, never private client records. Advisory/eligibility and distress messages are escalated before the model call.
- EP Assistant uses Claude for staff chat. Existing global switches, ownership checks, scoped record briefings and conversation persistence remain. Resumed record threads recheck access.
- Assessment analysis, ad generation and Social/Marketing integrations are unchanged. n8n can still be required by Social/Marketing; do not cancel the account on the basis of these chat changes alone.
- Both direct connectors start off and require an API key before generation. There is no n8n or other-provider fallback for either chat.
- No API key has been supplied or used in development. Provider calls are tested with HTTP fakes. Real generation remains unverified until the administrator adds their key.

## Deployment and rollback

No schema migration or new PHP dependency is required. `app_settings` stores encrypted credentials and per-surface options. `APP_KEY` must remain unchanged to decrypt saved credentials. Generic settings excludes this entire prefix and cannot overwrite it. Validation never flashes the API key. Logs omit credentials, prompts and provider error bodies.

Deploy only changed backend files/new Claude classes, config/claude.php and rebuilt frontend assets. Refresh config and route caches. Preserve the already-live blank/zero program-cost fix. Back up both changed sources and the previous frontend manifest before activation; upload assets before switching manifests. No .env, user files or DB contents are included in release archives.

The working branch is local. Uploading it to `ghamaypepito/luvep` still requires the repository-write authorization requested in the preceding task; a later GitHub deployment can overwrite a server-only release until that sync is authorized.

## References

- https://platform.claude.com/docs/en/api/messages/create
- https://platform.claude.com/docs/en/models/sonnet-5-5/migration-guide
- https://platform.claude.com/docs/en/about-claude/models/overview
