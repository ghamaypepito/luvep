# Direct Claude connectors implementation plan

Goal: Prepare direct Claude connectors for Pathy and EP Assistant with a secure API key configuration screen.
Architecture: Shared Laravel Messages transport, encrypted settings, per-assistant switches and prompts. React public chat calls local Laravel; staff chat retains existing scope.
Spec: docs/superpowers/specs/2026-10-25-direct-claude-connectors-design.md

## Global constraints
No n8n calls from either chat. Social/Marketing unchanged. No secrets in responses/logs/flash. No new write-capable AI tools. Preserve zero-cost display fix. No API calls until explicitly enabled/tested.

## Tasks
- [x] Add tests for encrypted configuration, authorization, key retention/redaction and API failures.
- [x] Implement connector settings and Anthropic client with fixed destination, bounded tokens/history, generic errors and no secret logging.
- [x] Add Pathy instructions/public context and deterministic escalation; route local chat with validation and throttling.
- [x] Switch staff chat transport while retaining scoped histories, kill switches and read-only context; recheck resumed record access.
- [x] Add Super Admin connector screen and navigation using incumbent settings styling. Fields: password key, per-assistant model/instructions/enabled, Save and Test connection.
- [x] Run targeted PHP tests, baseline comparison where needed, JSX/build checks and desktop/mobile browser verification.
- [x] Prepare deployment and usage instructions; leave actual model verification pending user API key.
