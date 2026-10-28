# Engagement Types Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Generate three kinds of client-specific engagement packs and require email/document review before sending.

**Architecture:** Extend existing PDF generators and modal. Add a workflow controller/service for temporary preparation, persistent drafts and reviewed sending; JSON document metadata keeps exact pack settings and identity without changing historical records.

**Tech Stack:** Laravel 12, React 19/Inertia, Dompdf, PHPUnit, Node/jsdom.

**Spec:** docs/superpowers/specs/2026-10-28-engagement-types-design.md

## Global Constraints

- Generate and Save as Draft send no email or SMS, including automation.
- Preserve existing Immigration Advice Services documents and family pricing.
- Consultation USD 250; OIA NZD 500 + 15% GST; auto-fill actual case/adviser data.
- Only an explicit Send Email after reviewing an unchanged saved pack may send.

## Review Focus

- Two engagement types for one client retain separate agreements and invoices.
- Missing required client/consultation details refuse preparation with useful errors.
- Changed PDFs or recipient after review invalidate the send token.
- Duplicate clicks and review tokens from other users/cases cannot send.
- Existing drafts, expired adviser licences, signed agreements and attachments remain protected.

### Task 1: Templates and pricing

**Files:** EngagementDocumentGenerator.php, InvoiceGenerator.php, new engagement Blade templates; tests/Feature/Immigration/EngagementWorkflowTest.php.
**Interfaces:** New document keys `initial_consultation`, `oia`; `engagement_type` selects service-specific invoice options; all PDFs use the selected case and adviser.
- [x] Add failing tests for automatic details and service-specific invoice currencies/totals.
- [x] Implement the templates, catalogue metadata, invoice currency and silent generation option.
- [x] Run focused PHP tests and visually inspect generated sample PDFs.

### Task 2: Generate, save and reviewed sending

**Files:** new EngagementWorkflowController.php and EngagementPackWorkflow.php, routes/web.php, signing controller, portal controller.
**Interfaces:** POST prepare -> temporary pack token/documents; POST save -> persisted pack ID; POST review -> rendered email/attachment manifest/review token; POST send-reviewed -> one send of the exact reviewed content.
- [x] Add failing tests for silent preparation/save, required fields, pack isolation, stale/cross-user reviews and repeated sends.
- [x] Implement private preparation, metadata persistence, review manifests, send locks and scoped signing.
- [x] Run the workflow and immigration regression suites.

### Task 3: Portal controls and review popup

**Files:** Engagement.jsx, new EngagementEmailReview.jsx, Node interaction regression.
**Interfaces:** Uses Task 2 endpoints; settings invalidate prepared/saved state; Verify & Email only opens review, Send Email performs delivery.
- [x] Add a failing DOM interaction test proving opening/cancelling review never sends.
- [x] Add the type dropdown, consultation inputs, separate buttons and accessible review popup.
- [x] Run frontend regressions, production build, browser desktop checks and responsive layout inspection and whole-branch review.
