# AI campaign brief references

Request: add optional file attachments beside the Marketing Workspace AI campaign brief and use them during generation.

The composer accepts up to three PDF, PNG, JPEG, WebP or UTF-8 TXT references, with Remove controls. Each file is limited to 4 MB, all files to 8 MB, and TXT to 50 KB. References remain in browser memory until generation sends them as multipart uploads to the existing authenticated caption/layout endpoints. They are not posting artwork, saved campaign media or public asset URLs; reloading requires reattachment.

The server checks extensions, detected MIME, count and size before calling Claude. PDFs and images become base64 Messages content blocks; text stays in a user content block. Trusted instructions remain separate. File contents and names are not written to activity logs or returned in generated responses. Existing AI access controls, throttles and publishing approvals remain in place.

Layout and caption drafts track a snapshot of the reference selection, preventing an old result being applied after references change. Starting or loading another campaign clears the files. Switching to manual upload mode excludes them from caption generation.

Validation: tests were first observed failing before implementation. The Marketing, Social and AI feature suites completed with 135 tests / 577 assertions (dependency deprecations under PHP 8.5). Four Node regressions pass, covering references, deferred saves, calendar navigation and post media. A focused independent review found no actionable issue.

Deployment: eight files activated with existing-file hash guards, new-file absence guards and a rollback backup at `/home/deploy/luvep-backups/brief-references-20261026`. No migrations, settings or saved post changes.

Protocol basis: Anthropic Messages supports [PDF base64 document blocks](https://platform.claude.com/docs/en/build-with-claude/pdf-support) and [image/text inputs](https://platform.claude.com/docs/en/build-with-claude/files).

Live verification completed: a harmless TXT was selected through the file chooser and appeared with its Remove control. Claude's returned caption included a phrase found only in that attachment, confirming browser → multipart endpoint → Claude → response. Removing the reference then trying to apply the old caption correctly requested fresh generation. The test campaign was never saved, submitted, scheduled or published; the new verification tab was reset to an empty AI campaign form. Screenshot: `../social-review-preview/live-ai-brief-references.png`.
