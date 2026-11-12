# TKT-KQBOQ8 — Superseded; do not implement Visa History for this request

Nova explicitly withdrew the requested Visa History classification in correction ticket TKT-8SOJL2. The reported file is a recent visa document, not visa-history evidence.

The feature introduced by commit 2e96d138 was reverted on this branch. Its original commit remains in Git history for audit; no production file was moved by this work. Do not cherry-pick the original Visa History feature for this case.

Review the replacement branch `codex/8sojl2-recent-visa-documents` for TKT-8SOJL2. It is based on current main and does not depend on this branch. Preserve the original file, case association, review state and upload audit details. No merge or production deployment is part of this withdrawal.
