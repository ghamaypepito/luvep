# Luvep Social workspace runtime

Versioned source for the authenticated workspace served by SocialWorkspaceTestController. Deployment copies runtime files into storage/app/private/social-workspace-test/v1; no public directory or Vite manifest overwrite is needed. index.html is served through the controller so CSRF and protected asset base are injected. Publish through the saved MarketingPost workflow only; browser preview approvals never authorize external posts. GHL data and post destination are fixed to the EP subaccount and Luv, Ep Facebook page.

The EP sample source templates remain in the exports directory of the existing deployed artifact. They are labelled as samples. build_samples.py regenerates these optional files using Pillow and the bundled fonts/assets. They are not generated AI designs. Exports are build artifacts, not Git source.

Run PHP focused tests for Social, Marketing and AI. Client save-race regression: node tests/Frontend/marketing-workflow-save.cjs. UTF-8 JavaScript is served with an explicit UTF-8 content type.
