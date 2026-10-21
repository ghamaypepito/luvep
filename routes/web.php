<?php

require __DIR__.'/hr.php';

use App\Http\Controllers\AccommodationController as PublicAccommodationController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\SuperAdminDashboardController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadDocumentController;
use App\Http\Controllers\LeadPortalInvitationController;
use App\Http\Controllers\LeadTrackingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Portal\Accommodation\CalendarController;
use App\Http\Controllers\Portal\Accommodation\GasDeliveryController;
use App\Http\Controllers\Portal\Accommodation\MessageTemplateController;
use App\Http\Controllers\Portal\Accommodation\PaymentScheduleController;
use App\Http\Controllers\Portal\Accommodation\RentUtilitiesController;
use App\Http\Controllers\Portal\Accommodation\TenantController;
use App\Http\Controllers\Portal\AccommodationController;
use App\Http\Controllers\Portal\EducationController;
use App\Http\Controllers\Portal\EnglishController;
use App\Http\Controllers\Portal\EoiSubmissionController;
use App\Http\Controllers\Portal\ImmigrationController;
use App\Http\Controllers\Portal\PropertyController;
use App\Http\Controllers\Portal\SalesController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\ProgramPromoController;
use App\Http\Controllers\QuickLeadController;
use App\Http\Controllers\ResidentIntakeController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentIntakeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserReviewController;
use App\Http\Controllers\VisaTypeController;
use App\Http\Controllers\VisitorIntakeController;
use App\Http\Controllers\WorkIntakeController;
use App\Services\NewsFeedService;
use App\Services\PromoFeed;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);

// Public XML sitemap for search engines. Lists only public/indexable pages;
// URLs use the fixed canonical base (never the request host). See SitemapController.
Route::get('/sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'index'])->name('sitemap');

// Local-only: render the engagement-documents email in the browser so the
// banner/icons/footer (served from this app's public/) load — email clients
// can't fetch localhost image URLs, so a delivered test shows broken images.
if (app()->environment('local')) {
    Route::get('/dev/engagement-preview', function () {
        $lead = \App\Models\Lead::whereNotNull('tracking_code')->first()
            ?? new \App\Models\Lead(['first_name' => 'Angelika', 'tracking_code' => 'PREVIEW']);

        // Map any already-generated engagement PDFs (source_variant
        // "engagement:<key>") to their doc ids so the preview's icons
        // deep-link to real files when they exist.
        $docIds = [];
        if ($lead->exists) {
            foreach ($lead->documents()->where('source_variant', 'like', 'engagement:%')->get() as $d) {
                $docIds[substr($d->source_variant, strlen('engagement:'))] = $d->id;
            }
        }

        return new \App\Mail\EngagementDocumentsReady(
            $lead,
            array_keys(\App\Services\Immigration\EngagementDocumentGenerator::DOCS),
            $docIds,
        );
    });
}

Route::get('/booking', function () {
    // Aggregate the immigration advisers' saved weekly availability into a
    // per-weekday time window (earliest start → latest end across all who set
    // it) so the public scheduler only offers times an adviser can meet.
    $adviserIds = \App\Models\User::whereIn('role', ['immigration', 'immigration_manager', 'immigration_adviser'])->pluck('id');
    $schedules = \App\Models\StaffAvailability::whereIn('user_id', $adviserIds)->pluck('schedule');
    $availability = [];
    foreach (\App\Models\StaffAvailability::DAYS as $day) {
        $starts = [];
        $ends = [];
        foreach ($schedules as $sched) {
            $d = $sched[$day] ?? null;
            if ($d && ($d['enabled'] ?? false) && ! empty($d['start']) && ! empty($d['end'])) {
                $starts[] = $d['start'];
                $ends[] = $d['end'];
            }
        }
        if ($starts) {
            $availability[$day] = ['start' => min($starts), 'end' => max($ends)];
        }
    }

    return inertia('booking/BookingPage', [
        'visaTypes' => \App\Models\VisaType::where('active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'short_description', 'consultation_price_nzd', 'consultation_duration_minutes'])
            ->map(fn ($v) => [
                'id' => $v->id,
                'code' => $v->code,
                'name' => $v->name,
                'description' => $v->short_description,
                'price' => (float) $v->consultation_price_nzd,
                'duration' => $v->consultation_duration_minutes,
            ]),
        'availability' => (object) $availability,
        'bookingTimezone' => config('services.booking.timezone', 'Pacific/Auckland'),
    ]);
});
Route::post('/bookings', [BookingController::class, 'store']);
// Busy times on the consultant's Google Calendar — drives slot availability on
// the public booking page (fails open when Calendar isn't configured).
Route::get('/booking/busy', [BookingController::class, 'busyTimes']);

// Client self-service reschedule / cancel from the booking emails. The token
// identifies the booking (known client → the booking page skips the intake
// form and only asks for a new slot). Cancel is a GET confirmation → POST.
Route::get('/booking/reschedule/{token}', [BookingController::class, 'reschedulePage'])->name('booking.reschedule');
Route::post('/booking/reschedule/{token}', [BookingController::class, 'reschedule'])->name('booking.reschedule.submit');
Route::get('/booking/cancel/{token}', [BookingController::class, 'cancelPage'])->name('booking.cancel');
Route::post('/booking/cancel/{token}', [BookingController::class, 'cancel'])->name('booking.cancel.submit');

// ── DTR (Daily Time & Task Record) ──────────────────────────────────────
// Per-user module surfaced in every staff portal's sidebar. All endpoints are
// user-scoped (they act on the logged-in user's own record), so plain `auth`
// is enough; the per-portal GET routes exist only so each renders under its
// own portal chrome (page name → layout, via thin re-export pages).
Route::middleware('auth')->group(function () {
    Route::post('/dtr/setup', [\App\Http\Controllers\DtrController::class, 'saveSetup'])->name('dtr.setup');
    Route::post('/dtr/entry', [\App\Http\Controllers\DtrController::class, 'saveEntry'])->name('dtr.entry');
    Route::post('/dtr/pending/toggle', [\App\Http\Controllers\DtrController::class, 'togglePending'])->name('dtr.pending.toggle');
    Route::post('/dtr/leaves', [\App\Http\Controllers\DtrController::class, 'fileLeave'])->name('dtr.leaves.file');
    Route::get('/dtr/leaves/{id}', [\App\Http\Controllers\DtrController::class, 'showLeave'])->name('dtr.leaves.show');
    Route::post('/dtr/leaves/{id}/review', [\App\Http\Controllers\DtrController::class, 'reviewLeave'])->name('dtr.leaves.review');
    Route::post('/dtr/time-in', [\App\Http\Controllers\DtrController::class, 'timeIn'])->name('dtr.timein');
    Route::post('/dtr/time-out', [\App\Http\Controllers\DtrController::class, 'timeOut'])->name('dtr.timeout');
    Route::get('/dtr/report', [\App\Http\Controllers\DtrController::class, 'dailyReport'])->name('dtr.report');

    Route::get('/admin/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('admin.dtr');
    Route::get('/admin/dtr/manage', [\App\Http\Controllers\DtrController::class, 'manage'])->name('admin.dtr.manage');
    Route::post('/admin/dtr/archive', [\App\Http\Controllers\DtrController::class, 'archiveStaff'])->name('admin.dtr.archive');
    Route::get('/admin/dtr/history/{user}', [\App\Http\Controllers\DtrController::class, 'settingHistory'])->name('admin.dtr.history');
    Route::get('/admin/dtr/reports', [\App\Http\Controllers\DtrController::class, 'reports'])->name('admin.dtr.reports');
    Route::get('/admin/dtr/weekly-report', [\App\Http\Controllers\DtrController::class, 'weeklyReport'])->name('admin.dtr.weekly');
    Route::post('/admin/dtr/weekly-report', [\App\Http\Controllers\DtrController::class, 'sendWeeklyReport'])->name('admin.dtr.weekly.send');
    Route::post('/admin/dtr/entry', [\App\Http\Controllers\DtrController::class, 'adminUpdateEntry'])->name('admin.dtr.entry.update');
    Route::delete('/admin/dtr/entry', [\App\Http\Controllers\DtrController::class, 'adminDeleteEntry'])->name('admin.dtr.entry.delete');
    Route::get('/admin/dtr/summary', [\App\Http\Controllers\DtrController::class, 'summary'])->name('admin.dtr.summary');
    Route::get('/portal/sales/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.sales.dtr');
    Route::get('/portal/education/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.education.dtr');
    Route::get('/portal/english/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.english.dtr');
    Route::get('/portal/immigration/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.immigration.dtr');
    Route::get('/portal/accommodation/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.accommodation.dtr');
    Route::get('/portal/agent/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.agent.dtr');
    Route::get('/portal/finance/dtr', [\App\Http\Controllers\DtrController::class, 'show'])->name('portal.finance.dtr');
});

// Stripe Checkout for consultation bookings (booking is saved first, then the
// optional payment step). Webhook is CSRF-exempt (see bootstrap/app.php).
Route::post('/bookings/{booking}/checkout', [\App\Http\Controllers\PaymentController::class, 'checkout']);
Route::get('/booking/payment/success', [\App\Http\Controllers\PaymentController::class, 'success'])->name('booking.payment.success');
Route::get('/booking/payment/cancel/{booking}', [\App\Http\Controllers\PaymentController::class, 'cancel'])->name('booking.payment.cancel');
Route::post('/stripe/webhook', [\App\Http\Controllers\PaymentController::class, 'webhook'])->name('stripe.webhook');

Route::get('/education-journey', function () {
    // Published programmes (title/slug/image) so the "Best 10 Green List"
    // carousel can match its curated cards to real programme records and
    // link + illustrate them. Matching is done client-side by title.
    $programs = \App\Models\Program::where('status', 'published')
        ->get(['title', 'slug', 'image'])
        ->map(fn ($p) => [
            'title' => $p->title,
            'slug' => $p->slug,
            'image_url' => $p->image ? \Illuminate\Support\Facades\Storage::disk('public')->url($p->image) : null,
        ])
        ->values();

    return inertia('education-journey/EducationJourneyPage', array_merge(
        UserReviewController::publicPayload(\App\Models\UserReview::DEPT_EDUCATION),
        ['activePromos' => PromoFeed::active(), 'programs' => $programs]
    ));
});

Route::get('/programs-levels', [ProgramController::class, 'publicIndex']);
Route::get('/program-details/{program}', [ProgramController::class, 'publicShow']);

Route::get('/fee-guide', [ProgramController::class, 'feeGuideIndex']);

Route::get('/about-us', function () {
    return inertia('about-us/AboutUsPage');
});

Route::get('/team/{slug}', fn (string $slug) => inertia('team/TeamProfilePage', [
    'slug' => $slug,
]));

Route::get('/immigration', function () {
    return inertia('immigration/ImmigrationPage', array_merge(
        UserReviewController::publicPayload(),
        ['news' => NewsFeedService::latest(3)]
    ));
});

// Admin moderation toggle for published / featured / status on a review.
// Both departments hit the same controller method — the moderation
// fields (is_published / is_featured / status / visa_type) are shared.
Route::middleware(['auth', 'portal:admin,immigration'])->post('/admin/immigration/user-reviews/{id}', [UserReviewController::class, 'adminUpdate'])
    ->name('admin.immigration.user-reviews.update');
Route::middleware(['auth', 'portal:admin,education'])->post('/admin/education/user-reviews/{id}', [UserReviewController::class, 'adminUpdate'])
    ->name('admin.education.user-reviews.update');

// Public inbound webhook from Zernio (new messages / comments). No auth — it's
// verified by Zernio's HMAC-SHA256 signature (X-Zernio-Signature) against
// ZERNIO_WEBHOOK_SECRET, and CSRF-exempt (see bootstrap/app.php). Bumps the
// inbox signal so open browsers refresh.
Route::post('/webhook/zernio', [App\Http\Controllers\AiAdsWebhookController::class, 'zernioWebhook'])->name('webhook.zernio');

Route::get('/accommodation', [PublicAccommodationController::class, 'index']);
// Expression of Interest — must be declared BEFORE /accommodation/{id} so the
// literal path isn't captured as an {id}.
Route::get('/accommodation/expression-of-interest-cold', [PublicAccommodationController::class, 'eoiForm'])->name('accommodation.eoi');
Route::post('/accommodation/expression-of-interest-cold', [PublicAccommodationController::class, 'eoiStore'])->name('accommodation.eoi.store');
Route::get('/accommodation/expression-of-interest-hot', [PublicAccommodationController::class, 'eoiHotForm'])->name('accommodation.eoi-hot');
Route::post('/accommodation/expression-of-interest-hot', [PublicAccommodationController::class, 'eoiHotStore'])->name('accommodation.eoi-hot.store');

// Native Pre-Tenancy form — the tokenised public form the onboarding email
// links to (replaces the external Google Form). No auth: the token is the
// bearer credential. Throttled like the other public intake forms.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/pre-tenancy/{token}', [\App\Http\Controllers\PreTenancyFormController::class, 'show'])->name('pre-tenancy.show');
    Route::post('/pre-tenancy/{token}', [\App\Http\Controllers\PreTenancyFormController::class, 'submit'])->name('pre-tenancy.submit');

    // Standalone ("general") pre-tenancy form — one shared link, not tied to any
    // onboarding record. Submissions feed the Forms → Pre-Tenancy list.
    Route::get('/apply/pre-tenancy', [\App\Http\Controllers\PreTenancyFormController::class, 'general'])->name('pre-tenancy.general');
    Route::post('/apply/pre-tenancy', [\App\Http\Controllers\PreTenancyFormController::class, 'storeGeneral'])->name('pre-tenancy.general.store');

    // Tokenised accommodation-agreement e-signing (emailed link). The signing
    // token is the bearer credential, like the immigration tracker signing.
    Route::get('/agreement/{token}/sign', [\App\Http\Controllers\AccommodationAgreementSigningController::class, 'show'])->name('agreement.sign.show');
    Route::get('/agreement/{token}/document', [\App\Http\Controllers\AccommodationAgreementSigningController::class, 'document'])->name('agreement.sign.document');
    Route::post('/agreement/{token}/sign', [\App\Http\Controllers\AccommodationAgreementSigningController::class, 'sign'])->name('agreement.sign');
    Route::get('/agreement/{token}/signed', [\App\Http\Controllers\AccommodationAgreementSigningController::class, 'signed'])->name('agreement.sign.signed');
    Route::get('/agreement/{token}/signed-pdf', [\App\Http\Controllers\AccommodationAgreementSigningController::class, 'downloadSigned'])->name('agreement.sign.signed-pdf');
});
// Public tenant concern form — declared before /accommodation/{slug}.
Route::get('/accommodation/concern', [App\Http\Controllers\ConcernController::class, 'form'])->name('accommodation.concern');
Route::post('/accommodation/concern', [App\Http\Controllers\ConcernController::class, 'store'])->name('accommodation.concern.store');

Route::get('/accommodation/{slug}', [PublicAccommodationController::class, 'show']);

Route::get('/accommodation/{id}/checkout', function ($id) {
    return inertia('accommodation/Checkout', ['id' => $id]);
});

Route::get('/coming-soon', function () {
    return inertia('coming-soon/ComingSoonPage');
});
Route::get('/immigration-assessment', function () {
    return inertia('visa/ImmigrationAssessment');
});

Route::get('/visa-assessment-form', function () {
    return inertia('visa/VisaAssessmentForm');
});

Route::get('/resident-interest', [ResidentIntakeController::class, 'showForm'])->name('resident-interest');
Route::post('/resident-interest', [ResidentIntakeController::class, 'store']);

// AEWV (Work) + Student (SV) + Visitor (GVV) intake forms. Each follows the
// same Resident pattern — submit creates an Assessment row that drives the
// Pay → Book funnel below.
Route::get('/work-interest', [WorkIntakeController::class,    'showForm'])->name('work-interest');
Route::post('/work-interest', [WorkIntakeController::class,    'store']);
Route::get('/student-interest', [StudentIntakeController::class, 'showForm'])->name('student-interest');
Route::post('/student-interest', [StudentIntakeController::class, 'store']);
Route::get('/visitor-interest', [VisitorIntakeController::class, 'showForm'])->name('visitor-interest');
Route::post('/visitor-interest', [VisitorIntakeController::class, 'store']);
Route::get('/family-interest', [\App\Http\Controllers\FamilyIntakeController::class, 'showForm'])->name('family-interest');
Route::post('/family-interest', [\App\Http\Controllers\FamilyIntakeController::class, 'store']);

// Auto-save for the five visa intakes. Each form posts its in-progress answers
// here every few seconds once a name + email are in; the draft is stored as a
// Lead with status 'Draft' so staff see it in Visa Assessment before the
// applicant ever submits. Throttled because it is a public, unauthenticated
// endpoint that writes.
Route::post('/visa-interest/{visa}/draft', [\App\Http\Controllers\VisaIntakeDraftController::class, 'store'])
    ->whereIn('visa', ['resident', 'work', 'student', 'visitor', 'family'])
    ->middleware('throttle:30,1')
    ->name('visa-interest.draft');

// Token-based edit links (no auth — the opaque token is the bearer credential).
Route::get('/resident-interest/edit/{token}', [ResidentIntakeController::class, 'showEditForm'])->name('resident-interest.edit');
Route::post('/resident-interest/edit/{token}', [ResidentIntakeController::class, 'updateFromEditLink']);

// Pay → Book funnel — visa-type-agnostic. Token is created when the
// intake form is submitted; only the original applicant holds it. The same
// routes serve every visa type via the polymorphic intakeable_* columns.
Route::get('/assessment/{token}/pay', [AssessmentController::class, 'showPay'])->name('assessment.pay');
Route::post('/assessment/{token}/pay', [AssessmentController::class, 'simulatePay'])->name('assessment.pay.simulate');
Route::get('/assessment/{token}/book', [AssessmentController::class, 'showBook'])->name('assessment.book');
Route::post('/assessment/{token}/book', [AssessmentController::class, 'claimSlot'])->name('assessment.book.claim');
Route::get('/assessment/{token}/booked', [AssessmentController::class, 'booked'])->name('assessment.booked');

// Keep the old path working for any in-flight bookmarks / external links.
Route::permanentRedirect('/resident-intake', '/resident-interest');

Route::post('/user-reviews', [UserReviewController::class, 'store'])->name('user-reviews.store');

// Shareable client-review submission page. Staff send this link to clients
// who have completed their service so they can leave a review without
// hunting through the immigration / education-journey pages. Accepts
// ?dept=education|immigration|both and ?name= / ?email= for prefill.
Route::get('/leave-review', function () {
    $allowed = ['immigration', 'education', 'both'];
    $dept = request('dept');

    return inertia('leave-review/LeaveReviewPage', [
        'department' => in_array($dept, $allowed, true) ? $dept : 'immigration',
        'prefill' => [
            'name' => request('name'),
            'email' => request('email'),
        ],
    ]);
})->name('leave-review');

Route::get('/activities', [EventController::class, 'activities']);

Route::get('/visa-approved', function (\Illuminate\Http\Request $request) {
    // Full published gallery — the admin CRUD lives at /admin/visa-approvals.
    // ?category=artist shows the artists section; anything else = students.
    $category = $request->query('category') === 'artist' ? 'artist' : 'student';

    return inertia('visa/VisaApproved', [
        'category' => $category,
        'visaApprovals' => \App\Models\VisaApproval::published()
            ->where('category', $category)
            ->orderByDesc('is_featured')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (\App\Models\VisaApproval $v) => $v->toPublicArray())
            ->all(),
    ]);
});

// Public application tracking — Ninja-Van-style lookup the department
// pastes to clients. /track shows the empty input; /track/{code} resolves
// a lead by tracking_code and renders info + documents + timeline. No
// auth: the tracking_code itself is the bearer credential. The same code
// authorises the lead to edit a tightly-scoped allow-list of fields and
// upload supporting documents (POST endpoints below).
Route::middleware(['throttle:tracker', 'tracker.enabled'])->group(function () {
    Route::get('/track', [LeadTrackingController::class, 'show'])->name('track');
    Route::get('/track/{code}', [LeadTrackingController::class, 'show'])->name('track.lookup');
    Route::post('/track/{code}/info', [LeadTrackingController::class, 'update'])->name('track.update');
    Route::post('/track/{code}/document', [LeadTrackingController::class, 'uploadDoc'])->name('track.upload');
    Route::post('/track/{code}/document/{doc}', [LeadTrackingController::class, 'updateDoc'])->name('track.doc.update');
    // Download a staff-shared / generated document (engagement pack).
    Route::get('/track/{code}/documents/{doc}/download', [LeadTrackingController::class, 'downloadDoc'])->name('track.doc.download');
    // Client's own uploads streamed from the private disk (code-gated). Public
    // URLs for these were a Privacy-Act exposure — see streamUpload().
    Route::get('/track/{code}/uploads/{doc}', [LeadTrackingController::class, 'streamUpload'])->name('track.upload.stream');
    // Live HTML preview for the signing modal (real-time signature).
    Route::get('/track/{code}/documents/{doc}/preview', [LeadTrackingController::class, 'previewDoc'])->name('track.doc.preview');
    // Client e-signs the generated Written Agreement.
    Route::post('/track/{code}/documents/{doc}/sign', [LeadTrackingController::class, 'signDoc'])->name('track.doc.sign');
    // Lead picks (or clears) one program from their staff-suggested
    // shortlist. Server validates the id is actually in the shortlist.
    Route::post('/track/{code}/choose-program', [LeadTrackingController::class, 'chooseProgram'])->name('track.choose-program');
    // JSON detail for the tracker's program modal. Scoped to programs staff
    // shortlisted for THIS lead, and — unlike the public /program-details page
    // — not gated on publication status: a draft program an adviser put in
    // front of a client is still theirs to read.
    Route::get('/track/{code}/programs/{program}', [LeadTrackingController::class, 'programDetails'])->name('track.program.details');
    Route::delete('/track/{code}/document/{doc}', [LeadTrackingController::class, 'deleteDoc'])->name('track.doc.delete');

    // Full program detail (JSON) for the staff-side ProgramDetailsModal on the
    // lead profile — any authenticated staff/agent can read catalogue info.
    Route::middleware('auth')->get('/admin/programs/{id}/details', [ProgramController::class, 'detailsJson'])->name('admin.programs.details');

    // Build 11.D Phase 3 — Agreement signing. tracker_signing_token in the
    // URL is the bearer credential for the agreement; the controller
    // validates that it belongs to the lead resolved from {code}, so a
    // valid code paired with a guessed token still 404s.
    Route::get('/track/{code}/agreements/{token}/sign', [\App\Http\Controllers\Tracker\TrackerAgreementController::class, 'showSigning'])->name('track.agreements.sign.show');
    Route::post('/track/{code}/agreements/{token}/sign', [\App\Http\Controllers\Tracker\TrackerAgreementController::class, 'sign'])->name('track.agreements.sign');
    Route::get('/track/{code}/agreements/{token}/signed', [\App\Http\Controllers\Tracker\TrackerAgreementController::class, 'signedConfirmation'])->name('track.agreements.signed');
});

// Standalone engagement-signing surface. The per-lead token
// (leads.engagement_signing_token) is the bearer credential; it grants access
// to ONLY that lead's engagement-pack documents (not the whole case), so it is
// independent of the tracker on/off toggle. Rate-limited like the tracker.
Route::middleware(['throttle:tracker'])->group(function () {
    Route::get('/engagement/{token}', [\App\Http\Controllers\EngagementSigningController::class, 'show'])->name('engagement.sign.show');
    Route::get('/engagement/{token}/documents/{doc}/download', [\App\Http\Controllers\EngagementSigningController::class, 'download'])->name('engagement.sign.download');
    Route::get('/engagement/{token}/documents/{doc}/preview', [\App\Http\Controllers\EngagementSigningController::class, 'preview'])->name('engagement.sign.preview');
    Route::post('/engagement/{token}/documents/{doc}/sign', [\App\Http\Controllers\EngagementSigningController::class, 'sign'])->name('engagement.sign');
    Route::post('/engagement/{token}/proof-of-payment', [\App\Http\Controllers\EngagementSigningController::class, 'uploadProof'])->name('engagement.proof');
});

// Public Registration & Assessment Routes
// Public "Quick Registration" — short marketing lead-capture form (a trimmed
// Free Assessment). Exact /register; the {event_code} variants below are the
// separate event-registration funnel.
Route::get('/register', [LeadController::class, 'showRegistration'])->name('register');
Route::post('/register', [LeadController::class, 'storeRegistration']);
Route::get('/register/full', [LeadController::class, 'showRegistrationFull'])->name('register.full');
Route::get('/register/{event_code}', [EventController::class, 'showRegistrationForm']);
Route::post('/register/{event_code}', [EventController::class, 'registerLead']);
Route::get('/free-assessment', [LeadController::class, 'showFreeAssessment'])->name('free-assessment');
Route::post('/free-assessment', [LeadController::class, 'storeFreeAssessment']);

// Education Enrolment — clean IntakeFormShell-based 7-step assessment.
// Separate controller method because the payload is simpler than the full
// free-assessment shape and gets tagged as `education-enrolment`.
Route::get('/education-enrolment', [LeadController::class, 'showEducationEnrolment'])->name('education-enrolment');
Route::post('/education-enrolment', [LeadController::class, 'storeEducationEnrolmentFull']);
Route::post('/education-enrolment/draft', [LeadController::class, 'saveAssessmentDraft'])->name('education-enrolment.draft');

// Free Assessment draft endpoint — same handler, tagged via URL.
Route::post('/free-assessment/draft', [LeadController::class, 'saveAssessmentDraft'])->name('free-assessment.draft');
Route::get('/assessment-result/{lead_id}', [LeadController::class, 'showAssessmentResult'])->name('assessment-result');

// Lightweight inline lead capture (hero, exit-intent, fee-guide download,
// footer newsletter, etc.) — writes into the same `leads` table with
// source-tagging so the sales pipeline sees one funnel.
Route::post('/quick-lead', [QuickLeadController::class, 'store'])->name('quick-lead');

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Self-service password reset (Laravel password broker, 60-min tokens).
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

// Lead Portal account setup — the invitation token itself acts as auth,
// so these routes sit outside the auth middleware.
Route::get('/lead-portal/setup/{token}', [LeadPortalInvitationController::class, 'setup'])
    ->name('lead-portal.setup')->middleware('guest');
Route::post('/lead-portal/setup/{token}', [LeadPortalInvitationController::class, 'store'])
    ->middleware('guest');

// External calendar sync — self-authenticates via the X-Sync-Token header
// (see SyncController), so it must sit OUTSIDE the auth/admin group.
Route::post('/api/sync-calendar', [App\Http\Controllers\SyncController::class, 'syncCalendar']);

// Authenticated areas — every staff user must be logged in ('auth'); role
// middleware nested below narrows each section ('portal:admin', 'portal:sales', …).
Route::middleware(['auth'])->group(function () {

    // System request tickets — any staff member can raise one (leads are
    // blocked inside the controller).
    Route::get('/system-tickets/{ticket}/conversation', [\App\Http\Controllers\TicketConversationController::class, 'show']);
    Route::post('/system-tickets/{ticket}/messages', [\App\Http\Controllers\TicketConversationController::class, 'storeMessage'])->middleware('throttle:30,1');
    Route::get('/system-tickets/{ticket}/resolution', [\App\Http\Controllers\TicketConversationController::class, 'downloadResolution']);
    Route::post('/tickets', [\App\Http\Controllers\SystemTicketController::class, 'store'])->name('tickets.store');
    // Screenshot stream — auth-gated to admins + the ticket's own submitter
    // inside the controller (the files live on the private disk).
    Route::get('/system-tickets/{id}/images/{index}', [\App\Http\Controllers\SystemTicketController::class, 'image'])
        ->whereNumber(['id', 'index'])->name('system-tickets.image');

    // Active message templates (JSON) — feeds the bulk-email + compose pickers.
    // Any authenticated staff member; no lead-specific data is exposed.
    Route::get('/api/message-templates', function () {
        return response()->json(
            \App\Models\MessageTemplate::active()->orderBy('name')
                ->get(['id', 'name', 'channels', 'email_subject', 'email_body', 'sms_body'])
        );
    })->name('api.message-templates');

    // Dedicated AI Assistant page — one context-aware surface. General by
    // default; ?subject_id scopes it to a record (authorised in the controller).
    Route::get('/assistant', [\App\Http\Controllers\AiAssistantController::class, 'show'])->name('assistant');

    // AI Foundation (Build 9) — JSON endpoints for the topbar chat panel and
    // the lead health badge. Session-auth (CSRF via X-XSRF-TOKEN from JS);
    // each handler re-checks the AI kill switch + per-user/role scoping.
    Route::prefix('api/ai')->name('api.ai.')->group(function () {
        Route::get('/conversations', [\App\Http\Controllers\Api\AiChatController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [\App\Http\Controllers\Api\AiChatController::class, 'show'])->name('conversations.show');
        Route::post('/messages', [\App\Http\Controllers\Api\AiChatController::class, 'sendMessage'])->name('messages.store');
        Route::delete('/conversations/{conversation}', [\App\Http\Controllers\Api\AiChatController::class, 'destroy'])->name('conversations.destroy');

        Route::get('/leads/{lead}/analysis', [\App\Http\Controllers\Api\AiLeadAnalysisController::class, 'show'])->name('leads.analysis.show');
        Route::post('/leads/{lead}/analysis/refresh', [\App\Http\Controllers\Api\AiLeadAnalysisController::class, 'refresh'])->name('leads.analysis.refresh');

        // Immigration cases are Lead records; {case} binds to Lead. Strict
        // role gate (admin + immigration staff only) lives in the controller.
        Route::get('/cases/{case}/analysis', [\App\Http\Controllers\Api\AiCaseAnalysisController::class, 'show'])->name('cases.analysis.show');
        Route::post('/cases/{case}/analysis/refresh', [\App\Http\Controllers\Api\AiCaseAnalysisController::class, 'refresh'])->name('cases.analysis.refresh');
    });

    // Profile avatar — role-agnostic; any authenticated user manages their own.
    Route::post('/profile/avatar', [UserController::class, 'uploadAvatar'])->name('profile.avatar.upload');
    Route::delete('/profile/avatar', [UserController::class, 'deleteAvatar'])->name('profile.avatar.delete');
    // Generic staff e-signature (any portal). Used on department agreements etc.
    Route::post('/profile/signature', [UserController::class, 'saveSignature'])->name('profile.signature.save');
    Route::delete('/profile/signature', [UserController::class, 'deleteSignature'])->name('profile.signature.delete');

    // Global search — Cmd+K across leads, tenants, properties, programs,
    // schools, English classes/assessments, applications, bookings.
    // Role-gated inside the service.
    Route::get('/api/search', [SearchController::class, 'search'])->name('search');

    // Notifications read path — role-agnostic; every authenticated user
    // reads their own in-app notifications (bell dropdown + full page).
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/api/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Super-admin-only — the top-of-house cross-department overview.
    // `portal:super_admin` is exact-role (see User::canAccessPortal), so
    // plain admins do NOT see this surface.
    Route::middleware('portal:super_admin')->group(function () {
        Route::get('/admin/super-dashboard', [SuperAdminDashboardController::class, 'dashboard'])
            ->name('admin.super-dashboard');

        // Public-site maintenance window. These routes are inside the
        // middleware's always-allow list, so they stay reachable while
        // maintenance is active — that's what prevents a lockout.
        Route::get('/admin/maintenance', [MaintenanceController::class, 'index'])
            ->name('admin.maintenance');
        Route::post('/admin/maintenance', [MaintenanceController::class, 'update'])
            ->name('admin.maintenance.update');
        Route::post('/admin/maintenance/bypass', [MaintenanceController::class, 'regenerateBypass'])
            ->name('admin.maintenance.bypass');
        Route::get('/admin/maintenance/preview', [MaintenanceController::class, 'preview'])
            ->name('admin.maintenance.preview');
        Route::post('/admin/maintenance/tracker', [MaintenanceController::class, 'updateTracker'])
            ->name('admin.maintenance.tracker');

        // Module Management — grant per-user access to restricted modules.
        Route::get('/admin/module-management', [\App\Http\Controllers\Admin\ModuleManagementController::class, 'index'])
            ->name('admin.module-management');
        Route::post('/admin/module-management/{user}', [\App\Http\Controllers\Admin\ModuleManagementController::class, 'update'])
            ->name('admin.module-management.update');
        // Grant a user access to extra department portals (beyond their role).
        Route::post('/admin/module-management/{user}/portals', [\App\Http\Controllers\Admin\ModuleManagementController::class, 'updatePortals'])
            ->name('admin.module-management.portals');
    });

    // Agents module — restricted (default super-admin-only, grantable per user
    // via Module Management). Admin-area surface behind both portal:admin and
    // the module gate.
    Route::middleware(['portal:admin', 'module:agents'])->group(function () {
        Route::get('/admin/agents', [\App\Http\Controllers\Admin\AgentModuleController::class, 'index'])
            ->name('admin.agents.index');
        Route::get('/admin/agents/{agent}', [\App\Http\Controllers\Admin\AgentModuleController::class, 'show'])
            ->name('admin.agents.show');
        Route::get('/admin/agents/{agent}/agreement/preview', [\App\Http\Controllers\Admin\AgentModuleController::class, 'previewAgreement'])
            ->name('admin.agents.agreement.preview');
        Route::post('/admin/agents/{agent}/agreement/generate', [\App\Http\Controllers\Admin\AgentModuleController::class, 'generateAgreement'])
            ->name('admin.agents.agreement.generate');
        Route::post('/admin/agents/{agent}/agreement/sign', [\App\Http\Controllers\Admin\AgentModuleController::class, 'signAgreement'])
            ->name('admin.agents.agreement.sign');
        Route::get('/admin/agents/{agent}/agreement/view', [\App\Http\Controllers\Admin\AgentModuleController::class, 'viewAgreement'])
            ->name('admin.agents.agreement.view');
        Route::get('/admin/agents/{agent}/agreement/download', [\App\Http\Controllers\Admin\AgentModuleController::class, 'downloadAgreement'])
            ->name('admin.agents.agreement.download');
    });

    // Verification module — restricted (default super-admin-only + admins,
    // grantable per user via Module Management, e.g. to Dinah). Two parts, each
    // its own grantable feature: Proposal (programmes) and Consultancy Agreement.
    // Gated only by the module so it's reachable from any portal the grantee is in.
    Route::middleware('module:program_verification.proposal')->group(function () {
        Route::get('/program-verification', [\App\Http\Controllers\ProgramVerificationController::class, 'index'])
            ->name('program-verification.index');
        Route::post('/program-verification/{lead}/programs', [\App\Http\Controllers\ProgramVerificationController::class, 'updatePrograms'])
            ->name('program-verification.programs');
        Route::post('/program-verification/{lead}/programs-meta', [\App\Http\Controllers\ProgramVerificationController::class, 'updateProgramMeta'])
            ->name('program-verification.programs-meta');
        Route::post('/program-verification/{lead}/request-changes', [\App\Http\Controllers\ProgramVerificationController::class, 'requestChanges'])
            ->name('program-verification.request-changes');
        Route::post('/program-verification/{lead}/verify', [\App\Http\Controllers\ProgramVerificationController::class, 'verify'])
            ->name('program-verification.verify');
        Route::post('/program-verification/{lead}/approve', [\App\Http\Controllers\ProgramVerificationController::class, 'approve'])
            ->name('program-verification.approve');
    });

    // Consultancy Agreement Verification — the consultancy counterpart, gated by
    // the same module's `consultancy` feature.
    Route::middleware('module:program_verification.consultancy')->group(function () {
        Route::get('/consultancy-verification', [\App\Http\Controllers\ConsultancyVerificationController::class, 'index'])
            ->name('consultancy-verification.index');
        Route::get('/consultancy-verification/{lead}/preview', [\App\Http\Controllers\ConsultancyVerificationController::class, 'preview'])
            ->name('consultancy-verification.preview');
        Route::post('/consultancy-verification/{lead}/meta', [\App\Http\Controllers\ConsultancyVerificationController::class, 'updateMeta'])
            ->name('consultancy-verification.meta');
        Route::post('/consultancy-verification/{lead}/request-changes', [\App\Http\Controllers\ConsultancyVerificationController::class, 'requestChanges'])
            ->name('consultancy-verification.request-changes');
        Route::post('/consultancy-verification/{lead}/verify', [\App\Http\Controllers\ConsultancyVerificationController::class, 'verify'])
            ->name('consultancy-verification.verify');
        Route::post('/consultancy-verification/{lead}/draft', [\App\Http\Controllers\ConsultancyVerificationController::class, 'draft'])
            ->name('consultancy-verification.draft');
        Route::post('/consultancy-verification/{lead}/approve', [\App\Http\Controllers\ConsultancyVerificationController::class, 'approve'])
            ->name('consultancy-verification.approve');
        Route::post('/consultancy-verification/{lead}/notes/{item}', [\App\Http\Controllers\ConsultancyVerificationController::class, 'addNote'])
            ->name('consultancy-verification.notes.add');
        Route::post('/consultancy-verification/{lead}/notes/{item}/{noteId}/reply', [\App\Http\Controllers\ConsultancyVerificationController::class, 'replyNote'])
            ->name('consultancy-verification.notes.reply');
        Route::post('/consultancy-verification/{lead}/notes/{item}/{noteId}/actioned', [\App\Http\Controllers\ConsultancyVerificationController::class, 'toggleActioned'])
            ->name('consultancy-verification.notes.actioned');
    });

    // Admin area — admin role only; department-portal staff are kept out by 'portal:admin'.
    Route::middleware('portal:admin')->group(function () {
        Route::redirect('/admin', '/admin/dashboard');
        Route::get('/admin/dashboard', function () {
            return inertia('admin/Dashboard', [
                'ticketSummary' => \App\Models\SystemTicket::dashboardSummary(),
            ]);
        });
        Route::get('/admin/team-cards', fn () => inertia('admin/TeamCards'))->name('admin.team-cards');
        Route::get('/admin/leads', [LeadController::class, 'index'])->name('admin.leads');
        Route::get('/admin/leads/proposals-agreements', [SalesController::class, 'proposalsAgreementsPage'])->name('admin.leads.proposals-agreements');
        Route::get('/admin/events', [EventController::class, 'index'])->name('admin.events');
        Route::post('/admin/events', [EventController::class, 'store']);
        Route::get('/admin/events/{id}', [EventController::class, 'show'])->name('admin.events.show');
        Route::post('/admin/events/{id}/email', [EventController::class, 'sendRegistrantEmail'])->name('admin.events.email');
        Route::post('/admin/events/{id}/schedule-email', [EventController::class, 'scheduleRegistrantEmail'])->name('admin.events.schedule-email');
        Route::post('/admin/events/{id}/scheduled-emails/{scheduledId}/cancel', [EventController::class, 'cancelScheduledEmail'])->name('admin.events.scheduled-email.cancel');
        Route::post('/admin/events/{id}', [EventController::class, 'update']);
        Route::delete('/admin/events/{id}', [EventController::class, 'destroy']);

        Route::get('/admin/programs', [ProgramController::class, 'index'])->name('admin.programs');
        Route::get('/admin/facebook-live', [App\Http\Controllers\FacebookLiveController::class, 'index'])->name('admin.facebook-live');
        Route::post('/admin/facebook-live', [App\Http\Controllers\FacebookLiveController::class, 'store']);
        Route::post('/admin/facebook-live/{id}', [App\Http\Controllers\FacebookLiveController::class, 'update']);
        Route::delete('/admin/facebook-live/{id}', [App\Http\Controllers\FacebookLiveController::class, 'destroy']);
        Route::post('/admin/programs', [ProgramController::class, 'store']);
        Route::post('/admin/programs/{id}', [ProgramController::class, 'update']);
        Route::delete('/admin/programs/{id}', [ProgramController::class, 'destroy']);

        Route::get('/admin/settings', [SettingController::class, 'index'])->name('admin.settings');
        Route::post('/admin/settings', [SettingController::class, 'update']);

        // Email automation — admin + super-admin choose which events send a
        // message, to whom, and with which template.
        Route::get('/admin/email-automation', [\App\Http\Controllers\Admin\EmailAutomationController::class, 'index'])->name('admin.email-automation');
        Route::post('/admin/email-automation', [\App\Http\Controllers\Admin\EmailAutomationController::class, 'save'])->name('admin.email-automation.save');
        Route::post('/admin/email-automation/test', [\App\Http\Controllers\Admin\EmailAutomationController::class, 'test'])->name('admin.email-automation.test');

        Route::get('/admin/availability', [AvailabilityController::class, 'index'])->name('admin.availability');
        Route::post('/admin/availability', [AvailabilityController::class, 'store']);
        Route::post('/admin/availability/{id}', [AvailabilityController::class, 'update']);
        Route::delete('/admin/availability/{id}', [AvailabilityController::class, 'destroy']);

        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users');
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::post('/admin/users/{id}', [UserController::class, 'update']);
        Route::delete('/admin/users/{id}', [UserController::class, 'destroy']);
        Route::post('/admin/users/{id}/restore', [UserController::class, 'restore']);
        Route::post('/admin/users/{id}/toggle-active', [UserController::class, 'toggleActive']);

        Route::get('/admin/activity-logs', [ActivityLogController::class, 'index'])->name('admin.activity-logs');

        // Per-department email branding (banner + CTA) — admin-managed presets.
        Route::get('/admin/email-branding', [\App\Http\Controllers\EmailBrandingController::class, 'index'])->name('admin.email-branding');
        Route::post('/admin/email-branding/{department}', [\App\Http\Controllers\EmailBrandingController::class, 'update'])->name('admin.email-branding.update');

        // Landing-page video testimonials (Facebook links).
        Route::get('/admin/video-testimonials', [\App\Http\Controllers\VideoTestimonialController::class, 'index'])->name('admin.video-testimonials');
        Route::post('/admin/video-testimonials', [\App\Http\Controllers\VideoTestimonialController::class, 'store'])->name('admin.video-testimonials.store');
        Route::put('/admin/video-testimonials/{testimonial}', [\App\Http\Controllers\VideoTestimonialController::class, 'update'])->name('admin.video-testimonials.update');
        Route::delete('/admin/video-testimonials/{testimonial}', [\App\Http\Controllers\VideoTestimonialController::class, 'destroy'])->name('admin.video-testimonials.destroy');

        // Message templates — staff-editable email/SMS templates.
        Route::get('/admin/message-templates', [\App\Http\Controllers\MessageTemplateController::class, 'index'])->name('admin.message-templates');
        Route::get('/admin/message-templates/create', [\App\Http\Controllers\MessageTemplateController::class, 'create'])->name('admin.message-templates.create');
        Route::get('/admin/message-templates/{id}/duplicate', [\App\Http\Controllers\MessageTemplateController::class, 'duplicate'])->name('admin.message-templates.duplicate');
        // Folder grouping (shared/global) — registered before the {id} show route.
        Route::post('/admin/message-templates/folders', [\App\Http\Controllers\MessageTemplateController::class, 'storeFolder'])->name('admin.message-templates.folders.store');
        Route::put('/admin/message-templates/folders/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'updateFolder'])->name('admin.message-templates.folders.update');
        Route::delete('/admin/message-templates/folders/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'destroyFolder'])->name('admin.message-templates.folders.destroy');
        Route::post('/admin/message-templates/folders/{id}/department', [\App\Http\Controllers\MessageTemplateController::class, 'moveFolderDepartment'])->name('admin.message-templates.folders.department');
        Route::post('/admin/message-templates/folders/{id}/duplicate', [\App\Http\Controllers\MessageTemplateController::class, 'duplicateFolder'])->name('admin.message-templates.folders.duplicate');
        Route::post('/admin/message-templates/move', [\App\Http\Controllers\MessageTemplateController::class, 'moveTemplates'])->name('admin.message-templates.move');
        Route::post('/admin/message-templates/move-department', [\App\Http\Controllers\MessageTemplateController::class, 'moveDepartment'])->name('admin.message-templates.move-department');
        Route::post('/admin/message-templates/delete', [\App\Http\Controllers\MessageTemplateController::class, 'destroyMany'])->name('admin.message-templates.delete-many');
        Route::post('/admin/message-templates/upload-image', [\App\Http\Controllers\MessageTemplateController::class, 'uploadEmailImage'])->name('admin.message-templates.upload-image');
        Route::post('/admin/message-templates', [\App\Http\Controllers\MessageTemplateController::class, 'store'])->name('admin.message-templates.store');
        Route::get('/admin/message-templates/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'show'])->name('admin.message-templates.show');
        Route::put('/admin/message-templates/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'update'])->name('admin.message-templates.update');
        Route::delete('/admin/message-templates/{id}', [\App\Http\Controllers\MessageTemplateController::class, 'destroy'])->name('admin.message-templates.destroy');
        Route::post('/admin/message-templates/{id}/test', [\App\Http\Controllers\MessageTemplateController::class, 'sendTest'])->name('admin.message-templates.test');

        // Email replies inbox — inbound replies pulled from the monitored
        // mailbox (IMAP), matched to leads. Staff read + reply here.
        // Bulk email — the same campaigns feature as the Sales portal
        // (compose / schedule / history), under the admin layout.
        Route::get('/admin/email/bulk', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('admin.email.bulk');
        Route::post('/admin/email/bulk', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('admin.email.bulk.store');
        Route::get('/admin/email/bulk/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('admin.email.bulk.show');
        Route::post('/admin/email/bulk/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('admin.email.bulk.cancel');

        // Bulk SMS — the same campaigns feature as bulk email, on the SMS
        // channel (template's SMS body, recipients with a phone). Admin-only.
        Route::get('/admin/email/sms', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('admin.email.sms');
        Route::post('/admin/email/sms', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('admin.email.sms.store');
        Route::get('/admin/email/sms/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('admin.email.sms.show');
        Route::post('/admin/email/sms/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('admin.email.sms.cancel');

        Route::get('/admin/email/replies', [\App\Http\Controllers\EmailReplyController::class, 'index'])->name('admin.email.replies');
        Route::post('/admin/email/replies/sync', [\App\Http\Controllers\EmailReplyController::class, 'syncNow'])->name('admin.email.replies.sync');

        // Compose — write a one-off email to picked leads and/or typed addresses.
        Route::get('/admin/email/compose', [\App\Http\Controllers\ComposeController::class, 'index'])->name('admin.email.compose');
        Route::post('/admin/email/compose/send', [\App\Http\Controllers\ComposeController::class, 'send'])->name('admin.email.compose.send');
        Route::post('/admin/email/compose/upload-image', [\App\Http\Controllers\ComposeController::class, 'uploadImage'])->name('admin.email.compose.upload-image');
        Route::get('/admin/email/compose/leads', [\App\Http\Controllers\ComposeController::class, 'searchLeads'])->name('admin.email.compose.leads');
        Route::post('/admin/email/replies/{leadId}/read', [\App\Http\Controllers\EmailReplyController::class, 'markThreadRead'])->name('admin.email.replies.read');
        Route::post('/admin/email/replies/{leadId}/reply', [\App\Http\Controllers\EmailReplyController::class, 'reply'])->name('admin.email.replies.reply');

        // System request tickets — admin + super-admin triage board.
        Route::get('/admin/system-tickets/analytics', \App\Http\Controllers\TicketAnalyticsController::class);
        Route::get('/admin/system-tickets/documentation', [\App\Http\Controllers\TicketConversationController::class, 'documentation']);
        Route::post('/admin/system-tickets/{ticket}/resolution', [\App\Http\Controllers\TicketConversationController::class, 'saveResolution']);
        Route::post('/admin/system-tickets/{ticket}/publish', [\App\Http\Controllers\TicketConversationController::class, 'publishDocumentation']);
        Route::get('/admin/system-tickets', [\App\Http\Controllers\SystemTicketController::class, 'adminIndex'])->name('admin.system-tickets');
        Route::post('/admin/system-tickets/{id}', [\App\Http\Controllers\SystemTicketController::class, 'adminUpdate'])->name('admin.system-tickets.update');

        // All Tasks — cross-department view. Same TaskBoardPage component the
        // department portals render, with admin scope (no assignee filter
        // by default + read-only on rows from other departments).
        Route::get('/admin/tasks', App\Http\Controllers\AdminTaskController::class)->name('admin.tasks');

        // AI Ads — Cerebras local copy brainstorming + PLAI Partner API for
        // launching to FB/IG/Google/LinkedIn/TikTok/etc. PLAI launch is
        // dormant until PLAI_API_KEY is set in .env (see config/services.php).
        Route::get('/admin/ai-ads', [App\Http\Controllers\AiAdController::class, 'index'])->name('admin.ai-ads');
        Route::get('/admin/ai-ads/plai/connection', [App\Http\Controllers\AiAdController::class, 'testConnection'])->name('admin.ai-ads.plai.connection');
        Route::post('/admin/ai-ads/generate', [App\Http\Controllers\AiAdController::class, 'generateCopy'])->name('admin.ai-ads.generate');
        Route::post('/admin/ai-ads/launch', [App\Http\Controllers\AiAdController::class, 'launch'])->name('admin.ai-ads.launch');

        // Social — restructured admin pages (Phase 2). Each route renders an
        // Inertia page under resources/js/pages/admin/social/. The bare /social
        // entry redirects to /social/compose so the sidebar link always lands
        // on a usable screen.
        Route::get('/social', fn () => redirect('/social/compose'))->name('social');
        Route::get('/social/compose', fn () => inertia('admin/social/Compose'))->name('social.compose');
        Route::get('/social/scheduled', fn () => inertia('admin/social/Scheduled'))->name('social.scheduled');
        Route::get('/social/inbox', fn () => inertia('admin/social/Inbox'))->name('social.inbox');
        Route::get('/social/ads', fn () => inertia('admin/social/Ads'))->name('social.ads');
        Route::get('/social/performance', fn () => inertia('admin/social/Performance'))->name('social.performance');
        Route::get('/social/accounts', fn () => inertia('admin/social/Accounts'))->name('social.accounts');

        // Social MVP webhook proxy — Laravel routes the React UI hits.
        // AiAdsWebhookController forwards to n8n when configured, otherwise
        // returns stub data so the UI can be built in isolation. See
        // app/Http/Controllers/AiAdsWebhookController.php for the contract.
        Route::prefix('webhook/social')->name('webhook.social.')->group(function () {
            Route::get('/stats', [App\Http\Controllers\AiAdsWebhookController::class, 'stats'])->name('stats');
            Route::post('/generate-variants', [App\Http\Controllers\AiAdsWebhookController::class, 'generateVariants'])->name('generate-variants');
            Route::get('/list-variants', [App\Http\Controllers\AiAdsWebhookController::class, 'listVariants'])->name('list-variants');
            Route::post('/update-variant', [App\Http\Controllers\AiAdsWebhookController::class, 'updateVariant'])->name('update-variant');
            Route::post('/reject-variant', [App\Http\Controllers\AiAdsWebhookController::class, 'rejectVariant'])->name('reject-variant');
            Route::post('/approve-variant', [App\Http\Controllers\AiAdsWebhookController::class, 'approveVariant'])->name('approve-variant');
            Route::get('/list-scheduled', [App\Http\Controllers\AiAdsWebhookController::class, 'listScheduled'])->name('list-scheduled');
            Route::post('/reschedule', [App\Http\Controllers\AiAdsWebhookController::class, 'reschedule'])->name('reschedule');
            Route::post('/cancel-post', [App\Http\Controllers\AiAdsWebhookController::class, 'cancelPost'])->name('cancel-post');
            Route::post('/quick-post', [App\Http\Controllers\AiAdsWebhookController::class, 'quickPost'])->name('quick-post');
            Route::get('/list-accounts', [App\Http\Controllers\AiAdsWebhookController::class, 'listAccounts'])->name('list-accounts');
            Route::post('/start-oauth', [App\Http\Controllers\AiAdsWebhookController::class, 'startOauth'])->name('start-oauth');
            Route::post('/disconnect', [App\Http\Controllers\AiAdsWebhookController::class, 'disconnectAccount'])->name('disconnect');

            // Inbox (Phase 3) — conversations, messages, comments.
            Route::get('/inbox-conversations', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxConversations'])->name('inbox-conversations');
            Route::get('/inbox-messages', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxMessages'])->name('inbox-messages');
            Route::post('/inbox-send', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxSend'])->name('inbox-send');
            Route::post('/inbox-read', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxRead'])->name('inbox-read');
            Route::get('/inbox-signal', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxSignal'])->name('inbox-signal');
            Route::get('/inbox-comments', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxComments'])->name('inbox-comments');
            Route::post('/inbox-reply-comment', [App\Http\Controllers\AiAdsWebhookController::class, 'inboxReplyComment'])->name('inbox-reply-comment');

            // Ads (Phase 3) — list, ad accounts, boost, analytics.
            Route::get('/published-posts', [App\Http\Controllers\AiAdsWebhookController::class, 'publishedPosts'])->name('published-posts');
            Route::get('/ads-list', [App\Http\Controllers\AiAdsWebhookController::class, 'adsList'])->name('ads-list');
            Route::get('/ad-accounts', [App\Http\Controllers\AiAdsWebhookController::class, 'adAccounts'])->name('ad-accounts');
            Route::post('/ads-boost', [App\Http\Controllers\AiAdsWebhookController::class, 'adsBoost'])->name('ads-boost');
            Route::post('/create-ad', [App\Http\Controllers\AiAdsWebhookController::class, 'createAd'])->name('create-ad');
            Route::post('/ai-ad-copy', [App\Http\Controllers\AiAdsWebhookController::class, 'aiAdCopy'])->name('ai-ad-copy');
            Route::get('/ad-analytics', [App\Http\Controllers\AiAdsWebhookController::class, 'adAnalytics'])->name('ad-analytics');
            Route::get('/ad-targeting-search', [App\Http\Controllers\AiAdsWebhookController::class, 'adTargetingSearch'])->name('ad-targeting-search');
            Route::post('/ai-targeting', [App\Http\Controllers\AiAdsWebhookController::class, 'aiTargeting'])->name('ai-targeting');
            Route::get('/ad-audiences', [App\Http\Controllers\AiAdsWebhookController::class, 'adAudiences'])->name('ad-audiences');
            Route::post('/ad-audience-save', [App\Http\Controllers\AiAdsWebhookController::class, 'adAudienceSave'])->name('ad-audience-save');

            // Performance (Phase 2b) — post analytics joined with lead counts.
            Route::get('/analytics', [App\Http\Controllers\AiAdsWebhookController::class, 'analytics'])->name('analytics');
        });

    });

    // Lead Portal invitations — admin approval / rejection / revocation.
    // Module-gated (NOT portal:admin) so the "Portal Invitations" module can be
    // granted to non-admin staff via Module Management and they can actually
    // reach the page; admins/super pass via the module's admin_default. Sales
    // requests are separate below.
    Route::middleware('module:portal_invitation')->group(function () {
        Route::get('/admin/portal-invitations', [LeadPortalInvitationController::class, 'adminIndex'])
            ->name('admin.portal-invitations');
        Route::post('/admin/leads/{id}/portal-invitation/approve', [LeadPortalInvitationController::class, 'approve'])
            ->name('admin.portal-invitation.approve');
        Route::post('/admin/leads/{id}/portal-invitation/reject', [LeadPortalInvitationController::class, 'reject'])
            ->name('admin.portal-invitation.reject');
        Route::post('/admin/leads/{id}/portal-invitation/revoke', [LeadPortalInvitationController::class, 'revoke'])
            ->name('admin.portal-invitation.revoke');
        Route::post('/admin/leads/{id}/portal-invitation/generate-credentials', [LeadPortalInvitationController::class, 'generateCredentials'])
            ->name('admin.portal-invitation.generate-credentials');
        Route::post('/admin/leads/{id}/portal-invitation/reset-password', [LeadPortalInvitationController::class, 'resetPassword'])
            ->name('admin.portal-invitation.reset-password');
    });

    // Consultation bookings — admin + education (education triages/converts
    // booking requests; the page has All / Education / Immigration tabs).
    Route::middleware('portal:admin,education')->group(function () {
        Route::get('/admin/booking', [BookingController::class, 'index'])->name('admin.bookings');
        Route::post('/admin/bookings/{id}', [BookingController::class, 'update']);
        Route::delete('/admin/bookings/{id}', [BookingController::class, 'destroy'])->name('admin.bookings.destroy');
        // Convert a booking's client into a pipeline lead (education flow).
        Route::post('/admin/bookings/{id}/convert', [BookingController::class, 'convertToLead'])->name('admin.bookings.convert');
    });

    // Program Promotions — admin / sales / education can manage time-bound
    // discount campaigns shown on the public Home + Education Journey +
    // Programs pages. Banner image uploads land in storage/app/public/promos.
    Route::middleware('portal:admin,sales,education')->group(function () {
        Route::get('/admin/promos', [ProgramPromoController::class, 'index'])->name('admin.promos');
        Route::post('/admin/promos', [ProgramPromoController::class, 'store']);
        Route::post('/admin/promos/{id}', [ProgramPromoController::class, 'update']);
        Route::delete('/admin/promos/{id}', [ProgramPromoController::class, 'destroy']);
    });

    // Lead detail view + stage update — any logged-in staff (admin OR any
    // department portal) can view a lead and advance its pipeline stage.
    // Every change is audited via the LogsActivity trait on the Lead model.
    Route::middleware('portal:admin,sales,education,english,immigration,accommodation')->group(function () {
        // Visa Approved CRUD — marketing showcase managed by every staff
        // role that can log into an admin sidebar. Public read is via
        // HomeController::index + the /visa-approved route (both pass
        // published approvals into the same VisaApprovedShowcase view).
        Route::get('/admin/visa-approvals', [\App\Http\Controllers\VisaApprovalController::class, 'index'])->name('admin.visa-approvals');
        Route::get('/admin/visa-approvals/people-search', [\App\Http\Controllers\VisaApprovalController::class, 'peopleSearch'])->name('admin.visa-approvals.people-search');
        Route::post('/admin/visa-approvals', [\App\Http\Controllers\VisaApprovalController::class, 'store'])->name('admin.visa-approvals.store');
        Route::post('/admin/visa-approvals/{id}', [\App\Http\Controllers\VisaApprovalController::class, 'update'])->name('admin.visa-approvals.update');
        Route::delete('/admin/visa-approvals/{id}', [\App\Http\Controllers\VisaApprovalController::class, 'destroy'])->name('admin.visa-approvals.destroy');

        // Per-registrant view: renders JUST the form the lead filled at
        // registration, plus an editable notes area. Any department that
        // owns leads may open it (sales sees registrants on the Leads
        // page's Events tab; other departments may drill in from their
        // own event dashboards).
        Route::get('/admin/events/{eventId}/registrants/{leadId}', [EventController::class, 'showRegistrant'])
            ->name('admin.events.registrant.show');
        Route::post('/admin/events/{eventId}/registrants/{leadId}/notes', [EventController::class, 'updateRegistrantNotes'])
            ->name('admin.events.registrant.notes');
        // Leads-page "Events" tab → full registrants table (reuses the sales
        // page under AdminLayout; SalesController picks the admin/ view by path).
        Route::get('/admin/events/{id}/registrants', [SalesController::class, 'eventRegistrantsPage'])
            ->name('admin.events.registrants');

        // Leads-page "Agents" tab → per-agent leads (reuses the sales page under
        // AdminLayout; SalesController picks the admin/ view + portalBase by path).
        Route::get('/admin/agents/{id}/leads', [SalesController::class, 'agentLeadsPage'])
            ->name('admin.agents.leads');

        // Manual "Add Lead" from the pipeline toolbar (dashboard add flow).
        Route::post('/admin/leads', [LeadController::class, 'storeDashboardLead'])->name('admin.leads.store');
        // Leads-page "Registration" tab → read-only snapshot of the /register form.
        Route::get('/admin/leads/{id}/registration', [SalesController::class, 'showLeadRegistration'])
            ->name('admin.leads.registration');

        // Bulk actions from the Leads-table toolbar (Set/edit agent, Delete).
        // The admin Leads page reuses the sales component with portalBase=/admin,
        // so it posts here. Declared BEFORE /admin/leads/{id} so the literal
        // "bulk-*" segments aren't captured as an {id}.
        Route::post('/admin/leads/bulk-agent', [SalesController::class, 'bulkAssignAgent'])->name('admin.leads.bulk-agent');
        Route::post('/admin/leads/bulk-delete', [SalesController::class, 'bulkDelete'])->name('admin.leads.bulk-delete');
        // Server-side lead search for the "+ New" proposal/agreement picker.
        Route::get('/admin/leads/doc-picker-search', [SalesController::class, 'docPickerSearch'])->name('admin.leads.doc-picker-search');
        // Assign / clear the recruiting agent on a single lead from the Edit
        // Lead modal (declared before /admin/leads/{id} so "{id}/agent" resolves).
        Route::post('/admin/leads/{id}/agent', [SalesController::class, 'updateLeadAgent'])->name('admin.leads.agent');

        Route::get('/admin/leads/{id}', [LeadController::class, 'show'])->name('admin.leads.show');
        Route::post('/admin/leads/{id}/stage', [LeadController::class, 'updateStage'])->name('admin.leads.stage');
        // Archive (soft-delete) a lead / case — used by the Cases + Leads row menus.
        Route::delete('/admin/leads/{id}', [LeadController::class, 'destroy'])->name('admin.leads.destroy');
        // General lead priority — shared by every portal's Leads table.
        Route::post('/admin/leads/{id}/priority', [LeadController::class, 'updatePriority'])->name('admin.leads.priority');
        // JSON payload for the Leads-table "Edit lead" modal.
        Route::get('/admin/leads/{id}/edit-data', [LeadController::class, 'editData'])->name('admin.leads.edit-data');
        // Re-send a proposal for verification (Proposals inbox ⋮ action).
        Route::post('/admin/leads/{id}/proposal/resubmit', [LeadController::class, 'resubmitProposal'])->name('admin.leads.proposal.resubmit');
        // Threaded per-programme notes for the Proposals review inbox.
        Route::post('/admin/leads/{lead}/program-notes/{program}', [\App\Http\Controllers\ProposalNoteController::class, 'store'])->name('admin.leads.program-notes.store');
        Route::post('/admin/leads/{lead}/program-notes/{program}/{note}/reply', [\App\Http\Controllers\ProposalNoteController::class, 'reply'])->name('admin.leads.program-notes.reply');
        Route::post('/admin/leads/{lead}/program-notes/{program}/{note}/actioned', [\App\Http\Controllers\ProposalNoteController::class, 'toggleActioned'])->name('admin.leads.program-notes.actioned');
        Route::delete('/admin/leads/{lead}/program-notes/{program}/{note}', [\App\Http\Controllers\ProposalNoteController::class, 'destroy'])->name('admin.leads.program-notes.destroy');
        Route::post('/admin/leads/{id}/personal', [LeadController::class, 'updatePersonal'])->name('admin.leads.personal');
        Route::post('/admin/leads/{id}/journey', [LeadController::class, 'updateJourney'])->name('admin.leads.journey');
        Route::post('/admin/leads/{id}/convert-to-student', [LeadController::class, 'convertToStudent'])->name('admin.leads.convert-student');
        Route::post('/admin/leads/{id}/revert-student', [LeadController::class, 'revertStudent'])->name('admin.leads.revert-student');
        Route::post('/admin/leads/{id}/convert-to-case', [LeadController::class, 'convertToCase'])->name('admin.leads.convert-case');
        Route::post('/admin/leads/{id}/revert-case', [LeadController::class, 'revertCase'])->name('admin.leads.revert-case');
        Route::post('/admin/leads/{id}/convert-to-accommodation', [LeadController::class, 'convertToAccommodation'])->name('admin.leads.convert-accommodation');
        Route::post('/admin/leads/{id}/revert-accommodation', [LeadController::class, 'revertAccommodation'])->name('admin.leads.revert-accommodation');
        Route::post('/admin/leads/{id}/convert-to-english', [LeadController::class, 'convertToEnglish'])->name('admin.leads.convert-english');
        Route::post('/admin/leads/{id}/revert-english', [LeadController::class, 'revertEnglish'])->name('admin.leads.revert-english');
        Route::post('/admin/leads/{id}/inz', [LeadController::class, 'updateInz'])->name('admin.leads.inz');
        Route::post('/admin/leads/{id}/send-tracker-link', [LeadController::class, 'sendTrackerLink'])->name('admin.leads.send-tracker-link');

        // Per-lead communications (Build 11.A) — compose a one-off message and
        // read the message history. Access is re-checked per lead inside the
        // controller (LeadAccess), so the broad route group can't leak a lead
        // across departments.
        Route::post('/admin/leads/{lead}/compose', [\App\Http\Controllers\Sales\ComposeMessageController::class, 'send'])->name('admin.leads.compose');
        Route::get('/admin/leads/{lead}/communications', [\App\Http\Controllers\Sales\LeadCommunicationsController::class, 'index'])->name('admin.leads.communications');
        // Reply to the lead from the conversation tab (blocked if they have no email).
        Route::post('/admin/leads/{lead}/communications', [\App\Http\Controllers\Sales\LeadCommunicationsController::class, 'reply'])->name('admin.leads.communications.reply');
        // Pull the mailbox on demand so a fresh reply appears without leaving the tab.
        Route::post('/admin/leads/{lead}/communications/sync', [\App\Http\Controllers\Sales\LeadCommunicationsController::class, 'sync'])->name('admin.leads.communications.sync');

        // Cross-lead document queue — review lead-submitted docs in bulk.
        Route::get('/admin/document-queue', [\App\Http\Controllers\DocumentQueueController::class, 'index'])->name('admin.document-queue');
        Route::post('/admin/document-queue/bulk', [\App\Http\Controllers\DocumentQueueController::class, 'bulk'])->name('admin.document-queue.bulk');

        // Bulk CSV import — duplicates detected by email or name+phone.
        Route::post('/admin/leads/import', [LeadController::class, 'importLeads'])->name('admin.leads.import');
        // Inline pipeline-stage change from the leads table / kanban. Declared
        // AFTER /admin/leads/import so the literal segment isn't caught as {id}.
        Route::post('/admin/leads/{id}', [LeadController::class, 'updateLeadStatus'])->name('admin.leads.status');
        // Inline "Visa applying for" change from the leads table dropdown.
        Route::post('/admin/leads/{id}/visa', [LeadController::class, 'updateLeadVisa'])->name('admin.leads.visa');
        Route::post('/admin/leads/{id}/documents/checklist', [LeadController::class, 'updateDocumentChecklist'])->name('admin.leads.documents.checklist');
        Route::post('/admin/leads/{id}/documents/section-verification', [LeadController::class, 'updateSectionVerification'])->name('admin.leads.documents.section-verification');

        // Per-document discussion threads on the lead-profile Documents tab —
        // mirrors the immigration Case Profile threads (shared CaseThread model).
        Route::post('/admin/leads/{id}/threads', [LeadController::class, 'storeDocThread'])->name('admin.leads.threads.store');
        Route::post('/admin/leads/{id}/threads/{thread}/resolve', [LeadController::class, 'resolveDocThread'])->name('admin.leads.threads.resolve');
        Route::patch('/admin/leads/{id}/threads/{thread}', [LeadController::class, 'updateDocThread'])->name('admin.leads.threads.update');

        // Inline edit of the proposed-program shortlist from the Lead Stats
        // "Programs offered" card (no proposal-version churn).
        Route::post('/admin/leads/{id}/shortlist', [LeadController::class, 'updateProposedShortlist'])->name('admin.leads.shortlist');
        // Per-program review from the "Programs offered" card: verified/rejected +
        // remarks + editable amount, saved onto leads.proposed_program_meta.
        Route::post('/admin/leads/{id}/programs/{program}/review', [LeadController::class, 'reviewProposedProgram'])->name('admin.leads.program-review');

        // Internal notes — any staff role can add, only author or admin can edit/delete.
        Route::get('/admin/leads/{id}/notes', [\App\Http\Controllers\LeadNoteController::class, 'index'])
            ->name('admin.leads.notes.index');
        Route::post('/admin/leads/{id}/notes', [\App\Http\Controllers\LeadNoteController::class, 'store'])
            ->name('admin.leads.notes.store');
        Route::post('/admin/leads/{leadId}/notes/{noteId}', [\App\Http\Controllers\LeadNoteController::class, 'update'])
            ->name('admin.leads.notes.update');
        Route::delete('/admin/leads/{leadId}/notes/{noteId}', [\App\Http\Controllers\LeadNoteController::class, 'destroy'])
            ->name('admin.leads.notes.destroy');
        Route::get('/admin/leads/{leadId}/notes/{noteId}/attachments/{index}', [\App\Http\Controllers\LeadNoteController::class, 'attachment'])
            ->name('admin.leads.notes.attachment');

        // Tags — free-form, auto-created on first use. /tags index feeds
        // the client-side autocomplete.
        Route::get('/admin/tags', [\App\Http\Controllers\LeadTagController::class, 'index'])
            ->name('admin.tags.index');
        Route::post('/admin/leads/{id}/tags', [\App\Http\Controllers\LeadTagController::class, 'attach'])
            ->name('admin.leads.tags.attach');
        Route::delete('/admin/leads/{leadId}/tags/{tagId}', [\App\Http\Controllers\LeadTagController::class, 'detach'])
            ->name('admin.leads.tags.detach');

        // Tasks — any staff can create + complete; only creator/admin can delete.
        Route::post('/admin/leads/{id}/tasks', [\App\Http\Controllers\LeadTaskController::class, 'store'])
            ->name('admin.leads.tasks.store');
        Route::post('/admin/leads/{leadId}/tasks/{taskId}', [\App\Http\Controllers\LeadTaskController::class, 'update'])
            ->name('admin.leads.tasks.update');
        Route::delete('/admin/leads/{leadId}/tasks/{taskId}', [\App\Http\Controllers\LeadTaskController::class, 'destroy'])
            ->name('admin.leads.tasks.destroy');
    });

    // Lead documents — admins AND sales agents both work these. The lead's
    // own upload routes are under the lead-portal group below. Build 11.D
    // Phase 4 widens the gate to immigration roles too: the Case Profile's
    // Documents tab is read+write for immigration consultants, so they need
    // the approve/reject/request endpoints. The case-side controller already
    // re-verifies is_immigration_case before letting the tab render, and
    // updateStatus is per-document so a sales lead's documents can't be
    // touched from this widened gate (no Lead Profile route routes them
    // here).
    Route::middleware('portal:admin,sales,immigration,immigration_manager,immigration_adviser,finance')->group(function () {
        Route::get('/admin/leads/{id}/documents', [LeadDocumentController::class, 'staffIndex'])
            ->name('admin.leads.documents');
        Route::post('/admin/leads/{id}/documents/requests', [LeadDocumentController::class, 'requestStore'])
            ->name('admin.leads.documents.request');
        Route::delete('/admin/leads/{leadId}/documents/requests/{requestId}', [LeadDocumentController::class, 'destroyRequest'])
            ->name('admin.leads.documents.request.destroy');
        Route::post('/admin/leads/{leadId}/documents/requests/{requestId}/resend', [LeadDocumentController::class, 'resendRequest'])
            ->name('admin.leads.documents.request.resend');
        // Staff upload a file against a request (client handed it over directly).
        Route::post('/admin/leads/{leadId}/documents/requests/{requestId}/upload', [LeadDocumentController::class, 'staffRequestUpload'])
            ->name('admin.leads.documents.request.upload');
        Route::post('/admin/leads/{leadId}/documents/{docId}/status', [LeadDocumentController::class, 'updateStatus'])
            ->name('admin.leads.documents.status');
        Route::post('/admin/leads/{id}/documents/share', [LeadDocumentController::class, 'shareWithLead'])
            ->name('admin.leads.documents.share');
        Route::post('/admin/leads/{leadId}/documents/{docId}/send-to-client', [LeadDocumentController::class, 'sendToClient'])
            ->name('admin.leads.documents.send-to-client');

        // Document formats — staff-built Word-style documents + their per-case use.
        Route::post('/admin/document-formats', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'store'])->name('admin.document-formats.store');
        // Static path — must be registered before the {format} routes below so
        // "send-to-case" isn't captured as a route-model binding.
        Route::post('/admin/document-formats/send-to-case', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'sendToCase'])->name('admin.document-formats.send-to-case');
        Route::post('/admin/document-formats/download', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'download'])->name('admin.document-formats.download');
        Route::post('/admin/document-formats/{format}', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'update'])->name('admin.document-formats.update');
        Route::delete('/admin/document-formats/{format}', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'destroy'])->name('admin.document-formats.destroy');
        Route::post('/admin/document-formats/{format}/apply', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'apply'])->name('admin.document-formats.apply');
        Route::delete('/admin/document-format-uses/{use}', [\App\Http\Controllers\Portal\DocumentFormatController::class, 'removeUse'])->name('admin.document-format-uses.destroy');
    });

    // Documents-tab checklist uploads + per-file delete + downloads — wider
    // group so every department portal that can see the documents tab can
    // also manage and download files. The download controller does its own
    // role-gated check on the specific lead before streaming the file.
    // Build 12 §2: immigration_manager + immigration_adviser get case document
    // write too — the adviser is no longer read-only; advice-bearing artifacts
    // are gated by AdviceBearingPolicy (licence), not by withholding routes.
    Route::middleware('portal:admin,sales,education,english,immigration,immigration_manager,immigration_adviser,accommodation,finance')->group(function () {
        // Staff "Book" action on a lead profile — creates a consultation booking
        // for the existing lead (no intake form) from the scheduler modal.
        Route::post('/admin/leads/{id}/booking', [BookingController::class, 'storeForLead'])
            ->name('admin.leads.booking');
        Route::post('/admin/leads/{id}/documents/checklist/{key}/upload', [LeadDocumentController::class, 'staffChecklistUpload'])
            ->name('admin.leads.documents.checklist.upload');
        // Per-lead ad-hoc document rows on the Documents tab — scoped to this
        // one lead (leads.custom_documents), uploaded against by a custom.* key.
        Route::post('/admin/leads/{id}/documents/custom', [LeadDocumentController::class, 'addCustomDocument'])
            ->name('admin.leads.documents.custom.add');
        Route::delete('/admin/leads/{id}/documents/custom/{key}', [LeadDocumentController::class, 'removeCustomDocument'])
            ->name('admin.leads.documents.custom.remove');
        // Templated agreement generator — Blade -> PDF -> attached as a
        // LeadDocument with source='generated'. Only agree.consultancy
        // (single|partner variant) is wired up right now.
        Route::post('/admin/leads/{id}/documents/checklist/{key}/generate', [LeadDocumentController::class, 'generateAgreement'])
            ->name('admin.leads.documents.checklist.generate');
        // Unified generate route driven by a friendly `type` — used by the
        // new Proposal & Agreements page's "+ New" flow. Supported types:
        //   proposal | consultancy_single | consultancy_partner | english_engagement
        Route::post('/admin/leads/{id}/generate/{type}', [LeadDocumentController::class, 'generateDocument'])
            ->name('admin.leads.generate');
        // Move an already-generated English agreement into the Consultancy
        // Agreement Verification queue (the ⋮ "Send for verification" action).
        Route::post('/admin/leads/{id}/documents/{document}/to-verification', [LeadDocumentController::class, 'sendEnglishToVerification'])
            ->name('admin.leads.document.to-verification');
        // Re-render an approved agreement PDF in place from the current amount
        // (no re-verification) — fixes file-vs-column drift.
        Route::post('/admin/leads/{id}/documents/{document}/regenerate', [LeadDocumentController::class, 'regenerateAgreement'])
            ->name('admin.leads.document.regenerate');
        // Bulk generate immigration engagement documents (written agreement
        // + IAA standards) for a case in one call — the Engagement workspace.
        Route::post('/admin/leads/{id}/engagement/generate', [LeadDocumentController::class, 'generateEngagement'])
            ->name('admin.leads.engagement.generate');
        // Live fee totals for the engagement modal receipt (family-aware).
        Route::get('/admin/leads/{id}/engagement/fee-totals', [LeadDocumentController::class, 'engagementFeeTotals'])
            ->name('admin.leads.engagement.fee-totals');
        // The case's family, with their "in agreement" flag, for the modal's
        // include checkboxes; and the toggle that flips it.
        Route::get('/admin/leads/{id}/engagement/family', [LeadDocumentController::class, 'engagementFamily'])
            ->name('admin.leads.engagement.family');
        Route::patch('/admin/leads/{id}/engagement/family/{dependent}', [LeadDocumentController::class, 'setEngagementFamilyInclusion'])
            ->name('admin.leads.engagement.family.toggle');
        // Set one applicant's (principal or dependant) professional fee override.
        Route::patch('/admin/leads/{id}/engagement/applicant-fee', [LeadDocumentController::class, 'setEngagementApplicantFee'])
            ->name('admin.leads.engagement.applicant-fee');
        // Email the client the engagement signing link (from the manage-draft modal).
        Route::post('/admin/leads/{id}/engagement/send', [LeadDocumentController::class, 'sendEngagement'])
            ->name('admin.leads.engagement.send');
        // Delete a case's whole generated engagement pack (one row = one case).
        Route::delete('/admin/leads/{id}/engagement/documents', [LeadDocumentController::class, 'destroyEngagementDocs'])
            ->name('admin.leads.engagement.destroy');
        // Tax invoice — live preview, generate, and per-case delete.
        Route::get('/admin/leads/{id}/invoice/preview', [LeadDocumentController::class, 'previewInvoice'])
            ->name('admin.leads.invoice.preview');
        Route::post('/admin/leads/{id}/invoice/generate', [LeadDocumentController::class, 'generateInvoice'])
            ->name('admin.leads.invoice.generate');
        Route::delete('/admin/leads/{id}/invoice/documents', [LeadDocumentController::class, 'destroyInvoices'])
            ->name('admin.leads.invoice.destroy');
        // Live HTML preview of the same Blade template — feeds the iframe
        // on the Proposal & Agreements "New" modal. Cheap: renders the
        // view without going through dompdf.
        Route::get('/admin/leads/{id}/generate/{type}/preview', [LeadDocumentController::class, 'previewDocument'])
            ->name('admin.leads.generate.preview');
        // "Proposal" — not a document. Staff pick up to 3 programs to
        // suggest to the lead; saved as a JSON array on the lead row.
        Route::post('/admin/leads/{id}/proposal', [LeadDocumentController::class, 'saveProposal'])
            ->name('admin.leads.proposal.save');
        // "Your proposal / agreement is ready" — queued email to the
        // lead pointing at their /track/{code} page. Fired from the
        // Notify button on the Proposal & Agreements table.
        Route::post('/admin/leads/{id}/notify-document-ready', [LeadDocumentController::class, 'notifyDocumentReady'])
            ->name('admin.leads.notify-document-ready');
        Route::delete('/admin/leads/{leadId}/documents/{docId}', [LeadDocumentController::class, 'destroyDocument'])
            ->name('admin.leads.documents.destroy');
        // JSON file list (with review statuses) for the Cases table's Files
        // popover — fetched on demand rather than shipped in the page props.
        Route::get('/admin/leads/{leadId}/documents/json', [LeadDocumentController::class, 'documentsJson'])
            ->name('admin.leads.documents.json');
        // Staff download — same controller, role-gated inside.
        Route::get('/admin/documents/{docId}/download', [LeadDocumentController::class, 'download'])
            ->name('admin.documents.download');
        // Inline note on a generated agreement (Proposal & Agreements table).
        Route::post('/admin/documents/{docId}/note', [LeadDocumentController::class, 'updateDocumentNote'])
            ->name('admin.documents.note');
        // Threaded staff notes on a generated document (add / reply / actioned).
        Route::post('/admin/documents/{docId}/notes', [LeadDocumentController::class, 'addDocumentNote'])
            ->name('admin.documents.notes.add');
        Route::post('/admin/documents/{docId}/notes/{noteId}/reply', [LeadDocumentController::class, 'replyDocumentNote'])
            ->name('admin.documents.notes.reply');
        Route::post('/admin/documents/{docId}/notes/{noteId}/actioned', [LeadDocumentController::class, 'toggleDocumentNoteActioned'])
            ->name('admin.documents.notes.actioned');
        // Bundle all of a lead's documents into a single ZIP.
        Route::get('/admin/leads/{leadId}/documents/download-all', [LeadDocumentController::class, 'downloadAll'])
            ->name('admin.leads.documents.download-all');
        // Toggle a checklist item's visibility on the public tracking link.
        Route::post('/admin/leads/{leadId}/documents/track-visibility', [LeadDocumentController::class, 'toggleTrackVisibility'])
            ->name('admin.leads.documents.track-visibility');

        // Task Board — cross-portal Task API for the New Task modal.
        // Lives in routes/web.php (not routes/api.php) so it inherits the
        // session-auth + CSRF middleware Inertia POSTs already use. The
        // existing per-lead POST /admin/leads/{id}/tasks endpoint is
        // unchanged; this is an additional path that also handles
        // department/personal tasks (no lead_id).
        Route::post('/api/tasks', [App\Http\Controllers\TaskController::class, 'store'])->name('api.tasks.store');
        Route::patch('/api/tasks/{id}', [App\Http\Controllers\TaskController::class, 'update'])->name('api.tasks.update');
        Route::post('/api/tasks/{id}/attachments', [App\Http\Controllers\TaskController::class, 'attach'])->name('api.tasks.attach');
        Route::get('/api/tasks/{id}/comments', [App\Http\Controllers\TaskController::class, 'comments'])->name('api.tasks.comments');
        Route::post('/api/tasks/{id}/comments', [App\Http\Controllers\TaskController::class, 'storeComment'])->name('api.tasks.comments.store');
        Route::get('/api/tasks/related-records', [App\Http\Controllers\TaskController::class, 'relatedRecords'])->name('api.tasks.related-records');
    });

    // Department-scoped email/SMS templates — every department portal manages
    // its own set through the shared MessageTemplateController, which scopes
    // itself to the acting staff member's department. Admins manage all via
    // /admin/message-templates above. The immigration portal middleware also
    // admits the immigration manager/adviser sub-roles.
    foreach (\App\Models\User::PORTAL_ROLES as $deptRole) {
        Route::middleware("portal:{$deptRole}")
            ->prefix("portal/{$deptRole}")
            ->name("portal.{$deptRole}.")
            ->group(function () {
                // Compose — one-off email to picked leads and/or typed addresses.
                $compose = \App\Http\Controllers\ComposeController::class;
                Route::get('/compose', [$compose, 'index'])->name('compose');
                Route::post('/compose/send', [$compose, 'send'])->name('compose.send');
                Route::post('/compose/upload-image', [$compose, 'uploadImage'])->name('compose.upload-image');
                Route::get('/compose/leads', [$compose, 'searchLeads'])->name('compose.leads');

                $c = \App\Http\Controllers\MessageTemplateController::class;
                Route::get('/email-templates', [$c, 'index'])->name('email-templates');
                Route::get('/email-templates/create', [$c, 'create'])->name('email-templates.create');
                Route::get('/email-templates/{id}/duplicate', [$c, 'duplicate'])->name('email-templates.duplicate');
                // Folder grouping (shared/global) — before the {id} show route.
                Route::post('/email-templates/folders', [$c, 'storeFolder'])->name('email-templates.folders.store');
                Route::put('/email-templates/folders/{id}', [$c, 'updateFolder'])->name('email-templates.folders.update');
                Route::delete('/email-templates/folders/{id}', [$c, 'destroyFolder'])->name('email-templates.folders.destroy');
                Route::post('/email-templates/folders/{id}/department', [$c, 'moveFolderDepartment'])->name('email-templates.folders.department');
                Route::post('/email-templates/folders/{id}/duplicate', [$c, 'duplicateFolder'])->name('email-templates.folders.duplicate');
                Route::post('/email-templates/move', [$c, 'moveTemplates'])->name('email-templates.move');
                Route::post('/email-templates/move-department', [$c, 'moveDepartment'])->name('email-templates.move-department');
                Route::post('/email-templates/delete', [$c, 'destroyMany'])->name('email-templates.delete-many');
                Route::post('/email-templates/upload-image', [$c, 'uploadEmailImage'])->name('email-templates.upload-image');
                Route::post('/email-templates', [$c, 'store'])->name('email-templates.store');
                Route::get('/email-templates/{id}', [$c, 'show'])->name('email-templates.show');
                Route::put('/email-templates/{id}', [$c, 'update'])->name('email-templates.update');
                Route::delete('/email-templates/{id}', [$c, 'destroy'])->name('email-templates.destroy');
                Route::post('/email-templates/{id}/test', [$c, 'sendTest'])->name('email-templates.test');

                // Per-department email branding — same page/chrome as this portal.
                $b = \App\Http\Controllers\EmailBrandingController::class;
                Route::get('/email-branding', [$b, 'index'])->name('email-branding');
                Route::post('/email-branding/{department}', [$b, 'update'])->name('email-branding.update');
            });
    }

    // Manual lead status-update emails — staff send a department (or shared)
    // template to a lead from the lead detail screen. Available to admins and
    // every department portal. The GET lists templates the user may send.
    Route::middleware('portal:admin,sales,education,english,immigration,accommodation')->group(function () {
        Route::get('/lead-message/templates', [\App\Http\Controllers\LeadMessageController::class, 'templates'])->name('lead-message.templates');
        Route::post('/leads/{lead}/send-message', [\App\Http\Controllers\LeadMessageController::class, 'send'])->name('leads.send-message');
    });

    // Immigration management screens — shared between admins and immigration-role staff.
    // They live under /admin/immigration/... for historical reasons; the immigration
    // portal's sidebar deep-links here.
    Route::middleware('portal:admin,immigration')->group(function () {
        Route::get('/admin/immigration/resident-intakes', [ResidentIntakeController::class, 'adminIndex'])->name('admin.immigration.resident-intakes');
        Route::get('/admin/immigration/resident-intakes/{id}', [ResidentIntakeController::class, 'adminShow'])->name('admin.immigration.resident-intakes.show');
        Route::get('/admin/immigration/resident-intakes/{id}/documents/{key}/{index?}', [ResidentIntakeController::class, 'downloadDocument'])->name('admin.immigration.resident-intakes.document');
        // Generic intake-document stream for the Work / Student / Visitor / Family
        // document tab — same private-disk streaming as resident, keyed by type.
        Route::get('/admin/immigration/intakes/{type}/{id}/documents/{key}/{index?}', [ImmigrationController::class, 'downloadIntakeDocument'])->name('admin.immigration.intakes.document');
        Route::post('/admin/immigration/resident-intakes/{id}/edit-link', [ResidentIntakeController::class, 'generateEditLink'])->name('admin.immigration.resident-intakes.edit-link');

        Route::get('/admin/immigration/user-reviews', fn () => app(UserReviewController::class)->adminIndex('immigration'))->name('admin.immigration.user-reviews');
        Route::get('/admin/immigration/user-reviews/{id}', fn ($id) => app(UserReviewController::class)->adminShow($id, 'immigration'))->name('admin.immigration.user-reviews.show');
    });

    // Education User Reviews — mirrors the immigration management screens.
    // Same controller + React components; the department arg scopes the
    // dataset to education-tagged reviews only.
    Route::middleware('portal:admin,education')->group(function () {
        Route::get('/admin/education/user-reviews', fn () => app(UserReviewController::class)->adminIndex('education'))->name('admin.education.user-reviews');
        Route::get('/admin/education/user-reviews/{id}', fn ($id) => app(UserReviewController::class)->adminShow($id, 'education'))->name('admin.education.user-reviews.show');

        // Schools catalog — education team uses this from their Setup
        // sidebar to add institutions students get placed into.
        Route::get('/admin/schools', [\App\Http\Controllers\SchoolController::class, 'index'])->name('admin.schools');
        Route::get('/admin/schools/{id}/profile', [\App\Http\Controllers\SchoolController::class, 'show'])->name('admin.schools.show');
        Route::get('/admin/schools/{id}/agreement', [\App\Http\Controllers\SchoolController::class, 'downloadAgreement'])->name('admin.schools.agreement');
        Route::post('/admin/schools', [\App\Http\Controllers\SchoolController::class, 'store']);
        Route::post('/admin/schools/{id}', [\App\Http\Controllers\SchoolController::class, 'update']);
        Route::delete('/admin/schools/{id}', [\App\Http\Controllers\SchoolController::class, 'destroy']);
    });

    // Unified User Reviews — single page with Immigration / Education
    // tabs. Replaces the two separate sidebar entries.
    Route::middleware('portal:admin,education,immigration')->group(function () {
        Route::get('/admin/user-reviews', [UserReviewController::class, 'adminUnifiedIndex'])->name('admin.user-reviews');
        // Interim manual import of a Google review (until the Business Profile
        // API access is approved). Same moderation queue as everything else.
        Route::post('/admin/user-reviews/google', [UserReviewController::class, 'adminStoreGoogle'])->name('admin.user-reviews.google');
    });

    // Department portals — each staff member reaches only their own portal
    // ('portal:<dept>'); admins may open any. Inertia/React pages under
    // resources/js/pages/portal/ rendered through DashboardLayout.
    Route::prefix('portal')->group(function () {
        Route::get('/', fn () => redirect(auth()->user()->homeRoute()))->name('portal');

        // "My Tickets" — every department staffer's own system-ticket list.
        // One route for all portals: the controller picks the per-role page
        // so it wraps in that staffer's layout (admins redirect to the board).
        Route::get('/tickets', [\App\Http\Controllers\SystemTicketController::class, 'myTickets'])->name('portal.tickets');

        // Sales portal — leads pipeline + consultation bookings.
        Route::middleware('portal:sales')->prefix('sales')->name('portal.sales.')->group(function () {
            Route::get('/dashboard', [SalesController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads', [SalesController::class, 'leads'])->name('leads');
            // Sidebar sub-item — leads that have a generated Proposal or
            // Agreement. Declared before /leads/{id} so the static suffix
            // segment wins.
            Route::get('/leads/proposals-agreements', [SalesController::class, 'proposalsAgreementsPage'])->name('leads.proposals-agreements');

            // Events tab — registrants for one event.
            //   /registrations → JSON (legacy; still used elsewhere)
            //   /registrants   → full-page Inertia render (Leads.jsx links here)
            Route::get('/events/{id}/registrations', [SalesController::class, 'eventRegistrations'])->name('events.registrations');
            Route::get('/events/{id}/registrants', [SalesController::class, 'eventRegistrantsPage'])->name('events.registrants');
            // Agents tab — full-page list of leads a recruiting agent has added,
            // styled like Open opportunities. Reached from the Agents tab
            // "View leads" action on portal/sales/Leads.
            Route::get('/agents/{id}/leads', [SalesController::class, 'agentLeadsPage'])->name('agents.leads');

            // Registration tab — read-only view of a lead's /register submission.
            // Declared before /leads/{id} so the /registration suffix wins.
            Route::get('/leads/{id}/registration', [SalesController::class, 'showLeadRegistration'])->name('leads.registration');

            // Bulk email (Build 11.A) — preview + send to the selected leads.
            // Declared before /leads/{id} so the two static segments win.
            Route::post('/leads/bulk-email/preview', [\App\Http\Controllers\Sales\BulkEmailController::class, 'preview'])->name('leads.bulk-email.preview');
            Route::post('/leads/bulk-email/send', [\App\Http\Controllers\Sales\BulkEmailController::class, 'send'])->name('leads.bulk-email.send');
            // Bulk assign-agent + bulk delete — drive the checkbox-selection
            // toolbar above the Open-opportunities table. Declared before
            // /leads/{id} so the static segments win.
            Route::post('/leads/bulk-agent', [SalesController::class, 'bulkAssignAgent'])->name('leads.bulk-agent');
            Route::post('/leads/bulk-delete', [SalesController::class, 'bulkDelete'])->name('leads.bulk-delete');

            Route::post('/leads', [SalesController::class, 'storeLead'])->name('leads.store');
            Route::post('/leads/{id}/notes', [\App\Http\Controllers\LeadNoteController::class, 'store'])->name('leads.notes.store');
            Route::post('/leads/{id}/agent', [SalesController::class, 'updateLeadAgent'])->name('leads.agent');
            Route::post('/leads/{id}', [SalesController::class, 'updateLead'])->name('leads.update');
            Route::post('/leads/{id}/visa', [LeadController::class, 'updateLeadVisa'])->name('leads.visa');
            Route::get('/bookings', [SalesController::class, 'bookings'])->name('bookings');
            Route::post('/bookings/{id}', [SalesController::class, 'updateBooking'])->name('bookings.update');

            // Programs catalogue — sales can add / edit / remove, same shared
            // ProgramController as education + admin (each action redirects back).
            Route::get('/programs', [SalesController::class, 'programs'])->name('programs');
            Route::post('/programs', [ProgramController::class, 'store'])->name('programs.store');
            Route::post('/programs/{id}', [ProgramController::class, 'update'])->name('programs.update');
            Route::delete('/programs/{id}', [ProgramController::class, 'destroy'])->name('programs.destroy');

            // Sales initiates portal access; admin must approve (see admin routes).
            Route::post('/leads/{id}/portal-invitation/request', [LeadPortalInvitationController::class, 'request'])
                ->name('leads.portal-invitation.request');

            // Tasks due-today widget feed for the sales dashboard.
            Route::get('/tasks/due-today', [\App\Http\Controllers\LeadTaskController::class, 'dueToday'])
                ->name('tasks.due-today');

            // Tasks & Follow-ups — central inbox of every open task across leads.
            Route::get('/tasks', [SalesController::class, 'tasks'])->name('tasks');

            // Students — the same screen Education owns, rendered under this
            // portal's layout (see EducationController::studentsComponent).
            // Read + the inline row edits, so the page is usable, not just
            // visible.
            Route::get('/students', [EducationController::class, 'students'])->name('students');
            Route::post('/students/{id}/dashboard-field', [EducationController::class, 'updateStudentField'])->name('students.dashboard-field');
            Route::post('/students', [EducationController::class, 'storeStudent'])->name('students.store');
            Route::post('/students/{id}/update', [EducationController::class, 'updateStudent'])->name('students.update');
            Route::post('/students/{id}/destroy', [EducationController::class, 'destroyStudent'])->name('students.destroy');

            // Sales Weekly Report — 11 sections; ?week_start=YYYY-MM-DD steps weeks.
            Route::get('/reports', [SalesController::class, 'report'])->name('reports');

            // Public assessment submissions (free-assessment + education-enrolment).
            Route::get('/assessments', [SalesController::class, 'assessments'])->name('assessments');

            // OUTREACH — /email-templates is served by MessageTemplateController
            // (department-scoped, registered above for every portal). Bulk email
            // (compose / schedule / history) is the BulkEmailController.
            Route::get('/bulk-email', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('bulk-email');
            Route::post('/bulk-email', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('bulk-email.store');
            Route::get('/bulk-email/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('bulk-email.show');
            Route::post('/bulk-email/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('bulk-email.cancel');
            Route::get('/campaigns', [SalesController::class, 'campaigns'])->name('campaigns');

            // ACCOUNT
            Route::get('/profile', [SalesController::class, 'profile'])->name('profile');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');

            // Portal-scoped lead detail URL (sales user lands at /portal/sales/leads/{id})
            Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show');
        });

        // Agent portal — recruiting agents who add leads and edit their info,
        // but can't touch stage/status, convert, or delete (those stay with
        // sales/admin). Everything here is scoped to the agent's own leads.
        Route::middleware('portal:agent')->prefix('agent')->name('portal.agent.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Portal\AgentController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads', [\App\Http\Controllers\Portal\AgentController::class, 'leads'])->name('leads');
            Route::post('/leads', [\App\Http\Controllers\Portal\AgentController::class, 'storeLead'])->name('leads.store');
            Route::post('/leads/{id}/info', [\App\Http\Controllers\Portal\AgentController::class, 'updateLeadInfo'])->name('leads.info');
            // Backs the shared Leads screen's Edit modal. Ownership-checked, and
            // limited to personal facts — no stage, priority or conversion.
            Route::get('/leads/{id}/edit-data', [\App\Http\Controllers\Portal\AgentController::class, 'leadEditData'])->name('leads.edit-data');
            Route::post('/leads/{id}/personal', [\App\Http\Controllers\Portal\AgentController::class, 'updateLeadPersonal'])->name('leads.personal');
            // Full lead profile under the Agent sidebar. Row-scoped to the
            // agent's own leads inside showLead(); declared after the static
            // /leads/* segments above so those keep winning.
            Route::get('/leads/{id}', [\App\Http\Controllers\Portal\AgentController::class, 'showLead'])->name('leads.show');
            // Students, read-only — this agent's own referrals who have since
            // been converted. Scoped inside students(); no write routes exist.
            Route::get('/students', [\App\Http\Controllers\Portal\AgentController::class, 'students'])
                ->middleware('module:referral_students')->name('students');
            // Cases — the agent's own referrals that became immigration cases.
            // Gated by the referral_cases module; row-scoped to agent_id inside
            // the controller (portal:agent proves the role only).
            Route::get('/cases', [\App\Http\Controllers\Portal\AgentController::class, 'cases'])
                ->middleware('module:referral_cases')->name('cases');
            Route::get('/profile', [\App\Http\Controllers\Portal\AgentController::class, 'profile'])->name('profile');
            Route::get('/agreement', [\App\Http\Controllers\Portal\AgentController::class, 'agreement'])->name('agreement');
            Route::post('/agreement/details', [\App\Http\Controllers\Portal\AgentController::class, 'updateAgreementDetails'])->name('agreement.details');
            Route::post('/agreement/sign', [\App\Http\Controllers\Portal\AgentController::class, 'signAgreement'])->name('agreement.sign');
            Route::get('/agreement/preview', [\App\Http\Controllers\Portal\AgentController::class, 'previewAgreement'])->name('agreement.preview');
            Route::get('/agreement/view', [\App\Http\Controllers\Portal\AgentController::class, 'viewAgreement'])->name('agreement.view');
            Route::get('/agreement/download', [\App\Http\Controllers\Portal\AgentController::class, 'downloadAgreement'])->name('agreement.download');
        });

        // Sub-agent portal — works ONE recruiting agent's referral leads
        // (their parent_agent_id) with a sales-style pipeline. Every action is
        // row-scoped to agent_id = parent_agent_id inside SubAgentController.
        Route::middleware('portal:sub_agent')->prefix('sub-agent')->name('portal.sub-agent.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Portal\SubAgentController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads', [\App\Http\Controllers\Portal\SubAgentController::class, 'leads'])->name('leads');
            Route::post('/leads', [\App\Http\Controllers\Portal\SubAgentController::class, 'storeLead'])->name('leads.store');
            // Static segments declared before /leads/{id} so they win.
            Route::post('/leads/{id}/notes', [\App\Http\Controllers\Portal\SubAgentController::class, 'storeNote'])->name('leads.notes.store');
            Route::post('/leads/{id}/priority', [\App\Http\Controllers\Portal\SubAgentController::class, 'updatePriority'])->name('leads.priority');
            // Referral-lead documents — Passport / CV / Diploma / TOR only.
            Route::get('/leads/{id}/documents', [\App\Http\Controllers\Portal\SubAgentController::class, 'documents'])->name('leads.documents');
            Route::post('/leads/{id}/documents', [\App\Http\Controllers\Portal\SubAgentController::class, 'storeDocument'])->name('leads.documents.store');
            Route::get('/leads/{id}/documents/{doc}/download', [\App\Http\Controllers\Portal\SubAgentController::class, 'downloadDocument'])->name('leads.documents.download');
            Route::post('/leads/{id}/documents/request', [\App\Http\Controllers\Portal\SubAgentController::class, 'requestDocuments'])->name('leads.documents.request');
            // Ad-hoc document slots beyond the four required ones.
            Route::post('/leads/{id}/documents/custom', [\App\Http\Controllers\Portal\SubAgentController::class, 'addCustomDocument'])->name('leads.documents.custom');
            Route::delete('/leads/{id}/documents/custom/{key}', [\App\Http\Controllers\Portal\SubAgentController::class, 'removeCustomDocument'])->name('leads.documents.custom.destroy');
            // Personal-tab field edits (tight allow-list) + the two lifecycle
            // flags the sub-agent owns (unresponsive / ready for review).
            Route::post('/leads/{id}/profile', [\App\Http\Controllers\Portal\SubAgentController::class, 'updateLeadProfile'])->name('leads.profile');
            Route::post('/leads/{id}/mark', [\App\Http\Controllers\Portal\SubAgentController::class, 'markLead'])->name('leads.mark');
            Route::post('/leads/{id}', [\App\Http\Controllers\Portal\SubAgentController::class, 'updateLead'])->name('leads.update');
            // Full lead profile under the Sub-agent sidebar. Row-scoped to the
            // parent agent's referrals inside showLead().
            Route::get('/leads/{id}', [\App\Http\Controllers\Portal\SubAgentController::class, 'showLead'])->name('leads.show');
            // Follow-ups — the same `lead_tasks` rows the Task Board works with,
            // presented as a cadence. Scoped to the parent agent's leads (plus
            // the sub-agent's own unlinked tasks) inside the controller.
            Route::get('/follow-ups', [\App\Http\Controllers\Portal\SubAgentController::class, 'followUps'])->name('follow-ups');
            Route::post('/follow-ups', [\App\Http\Controllers\Portal\SubAgentController::class, 'storeFollowUp'])->name('follow-ups.store');
            Route::post('/follow-ups/{id}', [\App\Http\Controllers\Portal\SubAgentController::class, 'updateFollowUp'])->name('follow-ups.update');
            // Students, read-only — the parent agent's referrals who have since
            // been converted. Scoped inside students(); no write routes exist.
            Route::get('/students', [\App\Http\Controllers\Portal\SubAgentController::class, 'students'])
                ->middleware('module:referral_students')->name('students');
            Route::get('/profile', [\App\Http\Controllers\Portal\SubAgentController::class, 'profile'])->name('profile');
        });

        /*
        |------------------------------------------------------------------
        | Lead profile — recruiting portals
        |------------------------------------------------------------------
        | The Agent and Sub-agent portals render the SAME lead profile screen
        | as sales, so they need the same write endpoints behind it. Rather
        | than fork thirty controller actions, the identical routes are
        | re-registered under each portal prefix, pointed at the very same
        | controllers, and fronted by `lead.scope` — which refuses any lead
        | outside the caller's own referrals before the controller runs.
        |
        | This is why the profile's URLs are portal-relative: posting to
        | /admin/... would both 403 (those groups exclude these roles) and,
        | on a redirect, drop the user into another portal's chrome.
        */
        $leadProfileRoutes = function () {
            $lead = \App\Http\Controllers\LeadController::class;
            $notes = \App\Http\Controllers\LeadNoteController::class;
            $tags = \App\Http\Controllers\LeadTagController::class;
            $tasks = \App\Http\Controllers\LeadTaskController::class;
            $docs = \App\Http\Controllers\LeadDocumentController::class;

            // ── Core record ───────────────────────────────────────────────
            Route::get('/leads/{id}/edit-data', [$lead, 'editData']);
            Route::post('/leads/{id}/personal', [$lead, 'updatePersonal']);
            Route::post('/leads/{id}/journey', [$lead, 'updateJourney']);
            Route::post('/leads/{id}/stage', [$lead, 'updateStage']);
            // The leads TABLE changes stage through this one (the profile uses
            // /stage above). Registered last for sub-agent, whose own scoped
            // handler is declared earlier in its group and therefore wins.
            Route::post('/leads/{id}', [$lead, 'updateLeadStatus']);
            Route::post('/leads/{id}/priority', [$lead, 'updatePriority']);
            Route::post('/leads/{id}/visa', [$lead, 'updateLeadVisa']);
            Route::post('/leads/{id}/inz', [$lead, 'updateInz']);
            // NOT mirrored: /leads/{id}/shortlist. Which programs go in front of
            // a client is staff's recommendation — recruiting agents read the
            // shortlist on the profile, they do not set it. The card hides the
            // controls; leaving the route off means a crafted request fails too.
            Route::post('/leads/{id}/send-tracker-link', [$lead, 'sendTrackerLink']);
            Route::delete('/leads/{id}', [$lead, 'destroy']);

            // ── Conversions (each reversible) ─────────────────────────────
            foreach (['student', 'case', 'accommodation', 'english'] as $target) {
                $verb = 'convertTo'.str($target)->studly();
                Route::post("/leads/{id}/convert-to-{$target}", [$lead, (string) $verb]);
                Route::post("/leads/{id}/revert-{$target}", [$lead, 'revert'.str($target)->studly()]);
            }

            // ── Notes / tags / tasks ──────────────────────────────────────
            Route::get('/leads/{id}/notes', [$notes, 'index']);
            Route::post('/leads/{id}/notes', [$notes, 'store']);
            Route::post('/leads/{leadId}/notes/{noteId}', [$notes, 'update']);
            Route::delete('/leads/{leadId}/notes/{noteId}', [$notes, 'destroy']);
            Route::get('/leads/{leadId}/notes/{noteId}/attachments/{index}', [$notes, 'attachment']);
            Route::post('/leads/{id}/tags', [$tags, 'attach']);
            Route::delete('/leads/{leadId}/tags/{tagId}', [$tags, 'detach']);
            Route::post('/leads/{id}/tasks', [$tasks, 'store']);
            Route::post('/leads/{leadId}/tasks/{taskId}', [$tasks, 'update']);
            Route::delete('/leads/{leadId}/tasks/{taskId}', [$tasks, 'destroy']);

            // ── Document threads (comments on a checklist item) ───────────
            Route::post('/leads/{id}/threads', [$lead, 'storeDocThread']);
            Route::post('/leads/{id}/threads/{thread}/resolve', [$lead, 'resolveDocThread']);
            Route::patch('/leads/{id}/threads/{thread}', [$lead, 'updateDocThread']);

            // ── Documents ─────────────────────────────────────────────────
            Route::get('/leads/{id}/documents', [$docs, 'staffIndex']);
            Route::get('/leads/{leadId}/documents/json', [$docs, 'documentsJson']);
            Route::get('/leads/{leadId}/documents/download-all', [$docs, 'downloadAll']);
            Route::post('/leads/{id}/documents/checklist', [$lead, 'updateDocumentChecklist']);
            Route::post('/leads/{id}/documents/section-verification', [$lead, 'updateSectionVerification']);
            Route::post('/leads/{id}/documents/checklist/{key}/upload', [$docs, 'staffChecklistUpload']);
            Route::post('/leads/{id}/documents/checklist/{key}/generate', [$docs, 'generateAgreement']);
            Route::post('/leads/{id}/documents/custom', [$docs, 'addCustomDocument']);
            Route::delete('/leads/{id}/documents/custom/{key}', [$docs, 'removeCustomDocument']);
            Route::post('/leads/{id}/documents/requests', [$docs, 'requestStore']);
            Route::delete('/leads/{leadId}/documents/requests/{requestId}', [$docs, 'destroyRequest']);
            Route::post('/leads/{leadId}/documents/requests/{requestId}/resend', [$docs, 'resendRequest']);
            Route::post('/leads/{leadId}/documents/{docId}/status', [$docs, 'updateStatus']);
            Route::post('/leads/{leadId}/documents/{docId}/send-to-client', [$docs, 'sendToClient']);
            Route::post('/leads/{id}/documents/share', [$docs, 'shareWithLead']);
            Route::post('/leads/{leadId}/documents/track-visibility', [$docs, 'toggleTrackVisibility']);
            Route::delete('/leads/{leadId}/documents/{docId}', [$docs, 'destroyDocument']);
        };

        Route::middleware(['portal:agent', 'lead.scope'])->prefix('agent')
            ->name('portal.agent.profile.')->group($leadProfileRoutes);
        Route::middleware(['portal:sub_agent', 'lead.scope'])->prefix('sub-agent')
            ->name('portal.sub-agent.profile.')->group($leadProfileRoutes);

        /*
        |----------------------------------------------------------------------
        | Agent Case management — the immigration Cases board + Case Profile and
        | their write surface, mirrored under the Agent portal so a First-
        | Immigration recruiting agent can work their OWN referrals' cases just
        | like staff (open/edit the profile, leave notes, set stage / visa /
        | priority), with the licensed adviser assisting on advice.
        |----------------------------------------------------------------------
        |
        | Same controllers immigration staff use — the board + profile are made
        | portal-relative (resources/js/lib/caseRoutes.js) so their buttons post
        | here instead of /portal/immigration|/admin. Three gates stack:
        |   portal:agent          — role
        |   module:referral_cases — granted per agent in Module Management
        |   lead.scope            — row-level: the {lead}/{id} must be agent_id=me
        | Advice-bearing actions (verdict, partner recommendation, lodgement
        | sign-off) stay licence-gated INSIDE the controllers, so an unlicensed
        | agent still can't author advice — that's where the adviser assists.
        */
        $agentCaseRoutes = function () {
            $imm = \App\Http\Controllers\Portal\ImmigrationController::class;
            $cp = \App\Http\Controllers\Immigration\CaseProfileController::class;
            $agr = \App\Http\Controllers\Immigration\AgreementController::class;
            $agent = \App\Http\Controllers\Portal\AgentController::class;
            $docs = \App\Http\Controllers\LeadDocumentController::class;

            // The Case Profile page itself, under Agent chrome.
            Route::get('/cases/{lead}/profile', [$agent, 'caseProfile']);

            // Board inline actions (stage, outcomes, RFI, visa, priority, handoff,
            // edit) — {id} is the case/lead id, so lead.scope scopes them. NOTE:
            // create ('/cases' POST) is deliberately NOT mirrored — an agent works
            // existing referrals, not brand-new cases.
            Route::post('/cases/{id}/stage', [$imm, 'updateCaseStage']);
            Route::post('/cases/{id}/decline', [$imm, 'declineVisa']);
            Route::post('/cases/{id}/outcome', [$imm, 'recordOutcome']);
            Route::post('/cases/{id}/rfi/analyze', [$imm, 'analyzeRfi']);
            Route::post('/cases/{id}/rfi', [$imm, 'requestForInformation']);
            Route::post('/cases/{id}/visa', [$imm, 'updateCaseVisa']);
            Route::post('/cases/{id}/priority', [$imm, 'updateCasePriority']);
            Route::post('/cases/{id}/handoff', [$imm, 'handoff']);
            Route::post('/cases/{id}', [$imm, 'updateCase']);

            // Case Profile write endpoints ({lead} = case/lead id).
            Route::post('/cases/{lead}/personal', [$cp, 'updatePersonal']);
            Route::get('/cases/{lead}/assessment-ai-note', [$cp, 'assessmentAiNote']);
            Route::post('/cases/{lead}/identity-scan', [$cp, 'identityScan']);
            Route::post('/cases/{lead}/findings/reevaluate', [$cp, 'reevaluateFindings']);
            Route::post('/cases/{lead}/findings/group-dismiss', [$cp, 'dismissFindingGroup']);
            Route::post('/cases/{lead}/findings/{finding}/dismiss', [$cp, 'dismissFinding']);
            Route::post('/cases/{lead}/steps/start', [$cp, 'startProcess']);
            Route::post('/cases/{lead}/steps/{step}/complete', [$cp, 'completeStep']);
            Route::post('/cases/{lead}/steps/{step}/reactivate', [$cp, 'reactivateStep']);
            Route::post('/cases/{lead}/payment', [$cp, 'recordPayment']);
            Route::post('/cases/{lead}/partner-recommendation', [$cp, 'partnerRecommendation']);
            Route::post('/cases/{lead}/verdict', [$cp, 'recordVerdict']);
            Route::post('/cases/{lead}/lodgement-signoff', [$cp, 'recordLodgementSignoff']);
            Route::post('/cases/{lead}/threads', [$cp, 'storeThread']);
            Route::post('/cases/{lead}/threads/{thread}/resolve', [$cp, 'resolveThread']);
            Route::patch('/cases/{lead}/threads/{thread}', [$cp, 'updateThread']);
            Route::post('/cases/{lead}/financials', [$cp, 'saveFinancials']);
            Route::post('/cases/{lead}/financials/payments', [$cp, 'addFinancePayment']);
            Route::delete('/cases/{lead}/financials/payments/{payment}', [$cp, 'deleteFinancePayment']);
            Route::post('/cases/{lead}/financials/invoice', [$cp, 'generateInvoice']);
            Route::get('/cases/{lead}/financials/invoice/preview', [$cp, 'previewInvoice']);
            Route::post('/cases/{lead}/inz-forms/{code}/generate', [$cp, 'generateInzForm'])->where('code', 'INZ[0-9]+');
            Route::post('/cases/{lead}/inz-forms/{code}/assign', [$cp, 'assignInzForm'])->where('code', 'INZ[0-9]+');

            // Dependants (sub-records + their documents).
            Route::post('/cases/{lead}/dependents', [$cp, 'addDependent']);
            Route::get('/cases/{lead}/dependent-source', [$cp, 'dependentSourceIdentity']);
            Route::put('/cases/{lead}/dependents/{dependent}', [$cp, 'updateDependent']);
            Route::patch('/cases/{lead}/dependents/{dependent}/in-agreement', [$cp, 'setDependentInAgreement']);
            Route::post('/cases/{lead}/dependents/{dependent}/open-case', [$cp, 'openDependentCase']);
            Route::delete('/cases/{lead}/dependents/{dependent}', [$cp, 'deleteDependent']);
            Route::post('/cases/{lead}/dependents/{dependent}/documents', [$cp, 'uploadDependentDocument']);
            Route::post('/cases/{lead}/dependents/{dependent}/documents/{document}/status', [$cp, 'setDependentDocumentStatus']);
            Route::post('/cases/{lead}/dependents/{dependent}/documents/{document}/note', [$cp, 'setDependentDocumentNote']);
            Route::delete('/cases/{lead}/dependents/{dependent}/documents/{document}', [$cp, 'deleteDependentDocument']);

            // Managed agreements — /templates before /{agreement} so the literal wins.
            Route::prefix('cases/{lead}/agreements')->group(function () use ($agr) {
                Route::get('/', [$agr, 'index']);
                Route::get('/templates', [$agr, 'templates']);
                Route::post('/', [$agr, 'generate']);
                Route::post('/{agreement}/send', [$agr, 'send']);
                Route::get('/{agreement}/pdf', [$agr, 'downloadPdf']);
                Route::post('/{agreement}/void', [$agr, 'void']);
            });

            // Client-portal invitation request from the board's row menu.
            Route::post('/leads/{id}/portal-invitation/request', [\App\Http\Controllers\LeadPortalInvitationController::class, 'request']);

            // Lead-scoped endpoints the Case Profile calls that aren't already in
            // $leadProfileRoutes ({id}/{lead}/{leadId} = lead id → lead.scope).
            Route::post('/leads/{lead}/compose', [\App\Http\Controllers\Sales\ComposeMessageController::class, 'send']);
            Route::post('/leads/{id}/engagement/generate', [$docs, 'generateEngagement']);
            Route::post('/leads/{id}/generate/{type}', [$docs, 'generateDocument']);
            Route::get('/leads/{id}/generate/{type}/preview', [$docs, 'previewDocument']);
            Route::post('/leads/{leadId}/documents/requests/{requestId}/upload', [$docs, 'staffRequestUpload']);

            // Single-document download. No lead id in the URL, so lead.scope can't
            // scope it — the wrapper re-checks the document's lead is agent_id=me.
            Route::get('/documents/{docId}/download', [$agent, 'downloadCaseDocument']);
        };
        Route::middleware(['portal:agent', 'module:referral_cases', 'lead.scope'])->prefix('agent')
            ->group($agentCaseRoutes);

        // Other portals — each has its own controller + dedicated dashboard
        // page. Admins satisfy every portal:* check via canAccessPortal(), so
        // they can view any role's dashboard.
        Route::middleware('portal:education')->prefix('education')->name('portal.education.')->group(function () {
            Route::get('/dashboard', [EducationController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads', [EducationController::class, 'leads'])->name('leads');
            Route::get('/leads/proposals-agreements', [SalesController::class, 'proposalsAgreementsPage'])->name('leads.proposals-agreements');
            // Events tab — registrants for one event (JSON drawer), same as sales.
            Route::get('/events/{id}/registrations', [EducationController::class, 'eventRegistrations'])->name('events.registrations');
            Route::get('/events/{id}/registrants', [EducationController::class, 'eventRegistrantsPage'])->name('events.registrants');
            // Agents tab — per-agent leads full page (shared sales screen, rendered
            // under EducationLayout with a /portal/education base by request path).
            Route::get('/agents/{id}/leads', [SalesController::class, 'agentLeadsPage'])->name('agents.leads');

            // Email module — shared Bulk Mail / SMS / Replies (same feature as
            // the sales + admin screens, rendered under this portal's layout).
            // Templates live at /email-templates (registered for every portal).
            Route::get('/bulk-email', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('bulk-email');
            Route::post('/bulk-email', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('bulk-email.store');
            Route::get('/bulk-email/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('bulk-email.show');
            Route::post('/bulk-email/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('bulk-email.cancel');
            Route::get('/sms', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('sms');
            Route::post('/sms', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('sms.store');
            Route::get('/sms/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('sms.show');
            Route::post('/sms/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('sms.cancel');
            Route::get('/email/replies', [\App\Http\Controllers\EmailReplyController::class, 'index'])->name('email.replies');
            Route::post('/email/replies/sync', [\App\Http\Controllers\EmailReplyController::class, 'syncNow'])->name('email.replies.sync');
            Route::post('/email/replies/{leadId}/read', [\App\Http\Controllers\EmailReplyController::class, 'markThreadRead'])->name('email.replies.read');
            Route::post('/email/replies/{leadId}/reply', [\App\Http\Controllers\EmailReplyController::class, 'reply'])->name('email.replies.reply');
            Route::post('/leads', [EducationController::class, 'storeLead'])->name('leads.store');
            // Bulk actions — mirror the sales portal so the shared Leads
            // component's checkbox-selection toolbar POSTs land on real
            // routes when the page is served under EducationLayout.
            // Declared before /leads/{id} so the two static segments win.
            Route::post('/leads/bulk-agent', [SalesController::class, 'bulkAssignAgent'])->name('leads.bulk-agent');
            Route::post('/leads/bulk-delete', [SalesController::class, 'bulkDelete'])->name('leads.bulk-delete');
            Route::post('/leads/{id}/notes', [\App\Http\Controllers\LeadNoteController::class, 'store'])->name('leads.notes.store');
            Route::post('/leads/{id}', [EducationController::class, 'updateLead'])->name('leads.update');
            Route::post('/leads/{id}/visa', [LeadController::class, 'updateLeadVisa'])->name('leads.visa');
            Route::post('/leads/{id}/portal-invitation/request', [LeadPortalInvitationController::class, 'request'])
                ->name('leads.portal-invitation.request');
            // Read-only registration snapshot — mirrors the sales route so the
            // shared Leads component's View link resolves under this portal.
            Route::get('/leads/{id}/registration', [SalesController::class, 'showLeadRegistration'])->name('leads.registration');
            Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show');

            // WORK
            // Bookings — same page as /admin/booking but rendered under the
            // education prefix so it keeps the Education sidebar.
            Route::get('/booking', [\App\Http\Controllers\BookingController::class, 'index'])->name('booking');
            Route::get('/students', [EducationController::class, 'students'])->name('students');
            Route::get('/students/intake-monitoring', [EducationController::class, 'intakeMonitoring'])->name('students.intake-monitoring');
            Route::post('/students/{id}/dashboard-field', [EducationController::class, 'updateStudentField'])->name('students.dashboard-field');
            // Add / edit / soft-delete student rows from the Students page.
            Route::post('/students', [EducationController::class, 'storeStudent'])->name('students.store');
            // "Add from existing person" — search leads not yet students, then
            // flag the chosen one as a student (links, does not duplicate).
            Route::get('/students/search-existing', [EducationController::class, 'searchExistingLeads'])->name('students.search-existing');
            Route::post('/students/link-existing', [EducationController::class, 'linkExistingStudent'])->name('students.link-existing');
            Route::post('/students/{id}/update', [EducationController::class, 'updateStudent'])->name('students.update');
            Route::post('/students/{id}/destroy', [EducationController::class, 'destroyStudent'])->name('students.destroy');
            // Schools catalog — same SchoolController as /admin/schools,
            // but routed under the education prefix so the page wraps in
            // EducationLayout instead of AdminLayout.
            Route::get('/schools', [\App\Http\Controllers\SchoolController::class, 'index'])->name('schools');
            Route::get('/schools/{id}/profile', [\App\Http\Controllers\SchoolController::class, 'show'])->name('schools.show');
            Route::get('/documents', [EducationController::class, 'documents'])->name('documents');
            // Public assessment submissions (free-assessment + education-enrolment).
            Route::get('/assessments', [EducationController::class, 'assessments'])->name('assessments');
            // Tasks & follow-ups (mirror of /portal/sales/tasks). Reuses
            // LeadTaskController::dueToday for the AJAX endpoint since
            // tasks are not department-scoped server-side.
            Route::get('/tasks/due-today', [\App\Http\Controllers\LeadTaskController::class, 'dueToday'])
                ->name('tasks.due-today');
            Route::get('/tasks', [EducationController::class, 'tasks'])->name('tasks');

            // SETUP
            Route::get('/programs', [EducationController::class, 'programs'])->name('programs');
            // Education staff can add / edit / remove programs from their portal
            // — same ProgramController as admin (each action redirects back),
            // just reachable under the education prefix.
            Route::post('/programs', [ProgramController::class, 'store'])->name('programs.store');
            Route::post('/programs/{id}', [ProgramController::class, 'update'])->name('programs.update');
            Route::delete('/programs/{id}', [ProgramController::class, 'destroy'])->name('programs.destroy');
            Route::get('/checklist-templates', [EducationController::class, 'checklistTemplates'])->name('checklist-templates');

            // REPORTS — single page; period (weekly|monthly|quarterly|custom)
            // is a query param, sections stay the same.
            Route::get('/reports', [EducationController::class, 'reports'])->name('reports');
            Route::post('/reports/note', [EducationController::class, 'saveReportNote'])->name('reports.note');

            // ACCOUNT
            Route::get('/profile', [EducationController::class, 'profile'])->name('profile');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        });

        Route::middleware('portal:english')->prefix('english')->name('portal.english.')->group(function () {
            Route::get('/dashboard', [EnglishController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show');

            // English learners — leads flagged is_english_student.
            Route::get('/learners', [EnglishController::class, 'learners'])->name('learners');

            // English classes — group sessions with learner enrollment.
            Route::get('/classes', [EnglishController::class, 'classes'])->name('classes');
            Route::post('/classes', [EnglishController::class, 'storeClass'])->name('classes.store');
            Route::get('/classes/{id}', [EnglishController::class, 'showClass'])->name('classes.show');
            Route::put('/classes/{id}', [EnglishController::class, 'updateClass'])->name('classes.update');
            Route::delete('/classes/{id}', [EnglishController::class, 'destroyClass'])->name('classes.destroy');
            Route::post('/classes/{id}/enroll', [EnglishController::class, 'enrollLearner'])->name('classes.enroll');
            Route::delete('/classes/{id}/enroll/{enrollmentId}', [EnglishController::class, 'withdrawLearner'])->name('classes.withdraw');

            // English assessments — mock / official test score records.
            Route::get('/assessments', [EnglishController::class, 'assessments'])->name('assessments');
            Route::post('/assessments', [EnglishController::class, 'storeAssessment'])->name('assessments.store');
            Route::get('/assessments/{id}', [EnglishController::class, 'showAssessment'])->name('assessments.show');
            Route::put('/assessments/{id}', [EnglishController::class, 'updateAssessment'])->name('assessments.update');
            Route::delete('/assessments/{id}', [EnglishController::class, 'destroyAssessment'])->name('assessments.destroy');
        });

        // Immigration Adviser (LIA) portal — separate from the manager's full
        // immigration portal, scoped to the adviser's own casework + sign-off.
        Route::middleware('portal:admin,immigration_adviser')->prefix('immigration-adviser')->name('portal.immigration-adviser.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'dashboard'])->name('dashboard');
            Route::get('/cases', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'cases'])->name('cases');
            Route::get('/my-cases', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'myCases'])->name('my-cases');
            Route::get('/tasks', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'tasks'])->name('tasks');
            Route::get('/client-documents', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'clientDocuments'])->name('client-documents');

            // Leads pipeline + Students dashboard — the same shared screens the
            // immigration portal uses, served under the adviser layout. The
            // action/nav routes below mirror the immigration ones so the shared
            // Leads/Students components' links resolve on this prefix (no 404)
            // and the adviser stays in their own portal chrome.
            Route::get('/leads', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'leads'])->name('leads');
            Route::post('/leads/bulk-agent', [SalesController::class, 'bulkAssignAgent'])->name('leads.bulk-agent');
            Route::post('/leads/bulk-delete', [SalesController::class, 'bulkDelete'])->name('leads.bulk-delete');
            Route::post('/leads/{id}', [ImmigrationController::class, 'updateLead'])->name('leads.update');
            Route::post('/leads/{id}/visa', [LeadController::class, 'updateLeadVisa'])->name('leads.visa');
            Route::post('/leads/{id}/portal-invitation/request', [LeadPortalInvitationController::class, 'request'])->name('leads.portal-invitation.request');
            Route::get('/leads/{id}/registration', [SalesController::class, 'showLeadRegistration'])->name('leads.registration');
            Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show');
            Route::get('/agents/{id}/leads', [SalesController::class, 'agentLeads'])->name('agents.leads');
            Route::get('/events/{id}/registrants', [SalesController::class, 'eventRegistrantsPage'])->name('events.registrants');

            Route::get('/students', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'students'])->name('students');
            Route::post('/students/{id}/dashboard-field', [\App\Http\Controllers\Portal\EducationController::class, 'updateStudentField'])->name('students.dashboard-field');
            Route::post('/students', [\App\Http\Controllers\Portal\EducationController::class, 'storeStudent'])->name('students.store');
            Route::post('/students/{id}/update', [\App\Http\Controllers\Portal\EducationController::class, 'updateStudent'])->name('students.update');
            Route::post('/students/{id}/destroy', [\App\Http\Controllers\Portal\EducationController::class, 'destroyStudent'])->name('students.destroy');
            Route::get('/assessments', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'assessments'])->name('assessments');
            Route::get('/cases/{lead}', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'showCase'])->name('cases.show');
            // Document verification queue — manager-checked docs the LIA approves/rejects.
            Route::get('/engagement', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'engagement'])->name('engagement');
            Route::get('/invoice', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'invoice'])->name('invoice');
            Route::get('/inz-forms', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'inzForms'])->name('inz-forms');
            Route::get('/visas', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'visas'])->name('visas');
            Route::get('/verification', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'verification'])->name('verification');
            Route::post('/verification/{document}', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'verifyDocument'])->name('verification.verify');
            // Full-page document review for a case.
            Route::get('/verification/review/{lead}', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'review'])->name('verification.review');
            // AI "document vs client record" scan for the review page.
            Route::post('/verification/{document}/ai-scan', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'aiScanDocument'])->name('verification.ai-scan');
            Route::get('/reports', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'reports'])->name('reports');
            Route::get('/sign-off', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'signOff'])->name('sign-off');
            Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications');
            Route::get('/profile', [\App\Http\Controllers\Portal\ImmigrationAdviserController::class, 'profile'])->name('profile');
        });

        Route::middleware('portal:immigration')->prefix('immigration')->name('portal.immigration.')->group(function () {
            Route::get('/dashboard', [ImmigrationController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads', [ImmigrationController::class, 'leads'])->name('leads');
            Route::get('/leads/proposals-agreements', [SalesController::class, 'proposalsAgreementsPage'])->name('leads.proposals-agreements');

            // Email module — shared Bulk Mail / SMS / Replies (same feature as
            // the sales + admin screens, rendered under this portal's layout).
            // Templates live at /email-templates (registered for every portal).
            Route::get('/bulk-email', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('bulk-email');
            Route::post('/bulk-email', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('bulk-email.store');
            Route::get('/bulk-email/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('bulk-email.show');
            Route::post('/bulk-email/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('bulk-email.cancel');
            Route::get('/sms', [\App\Http\Controllers\BulkEmailController::class, 'index'])->name('sms');
            Route::post('/sms', [\App\Http\Controllers\BulkEmailController::class, 'store'])->name('sms.store');
            Route::get('/sms/{id}', [\App\Http\Controllers\BulkEmailController::class, 'show'])->name('sms.show');
            Route::post('/sms/{id}/cancel', [\App\Http\Controllers\BulkEmailController::class, 'cancel'])->name('sms.cancel');
            Route::get('/email/replies', [\App\Http\Controllers\EmailReplyController::class, 'index'])->name('email.replies');
            Route::post('/email/replies/sync', [\App\Http\Controllers\EmailReplyController::class, 'syncNow'])->name('email.replies.sync');
            Route::post('/email/replies/{leadId}/read', [\App\Http\Controllers\EmailReplyController::class, 'markThreadRead'])->name('email.replies.read');
            Route::post('/email/replies/{leadId}/reply', [\App\Http\Controllers\EmailReplyController::class, 'reply'])->name('email.replies.reply');
            // Events tab — registrants for one event (JSON drawer), same as sales.
            Route::get('/events/{id}/registrations', [ImmigrationController::class, 'eventRegistrations'])->name('events.registrations');
            Route::get('/events/{id}/registrants', [ImmigrationController::class, 'eventRegistrantsPage'])->name('events.registrants');
            // Bulk actions — mirror the sales portal so the shared Leads
            // component's checkbox-selection toolbar POSTs land on real
            // routes when the page is served under ImmigrationLayout.
            // Declared before /leads/{id} so the two static segments win.
            Route::post('/leads/bulk-agent', [SalesController::class, 'bulkAssignAgent'])->name('leads.bulk-agent');
            Route::post('/leads/bulk-delete', [SalesController::class, 'bulkDelete'])->name('leads.bulk-delete');
            Route::post('/leads/{id}', [ImmigrationController::class, 'updateLead'])->name('leads.update');
            Route::post('/leads/{id}/visa', [LeadController::class, 'updateLeadVisa'])->name('leads.visa');
            Route::post('/leads/{id}/portal-invitation/request', [LeadPortalInvitationController::class, 'request'])
                ->name('leads.portal-invitation.request');
            // Read-only registration snapshot — mirrors the sales route so the
            // shared Leads component's View link resolves under this portal.
            Route::get('/leads/{id}/registration', [SalesController::class, 'showLeadRegistration'])->name('leads.registration');
            Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show');

            // WORK
            Route::get('/assessments', [ImmigrationController::class, 'assessments'])->name('assessments');
            // Per-intake detail page — covers Work / Student / Visitor.
            // Resident intakes use the dedicated /admin/immigration/
            // resident-intakes/{id} route (richer document handling).
            Route::get('/intakes/{type}/{id}', [ImmigrationController::class, 'showIntake'])
                ->where(['type' => 'work|student|visitor', 'id' => '[0-9]+'])
                ->name('intakes.show');
            // Submitted visa-interest form as JSON — the Assessments "Open" modal.
            Route::get('/intakes/{type}/{id}/data', [ImmigrationController::class, 'intakeData'])
                ->where(['type' => 'resident|work|student|visitor|family', 'id' => '[0-9]+'])
                ->name('intakes.data');
            // Staff inline edit of an intake's form fields from the "Open" modal
            // ('free' edits the underlying Lead's Personal-detail columns).
            Route::patch('/intakes/{type}/{id}', [ImmigrationController::class, 'updateIntakeFields'])
                ->where(['type' => 'resident|work|student|visitor|family|free', 'id' => '[0-9]+'])
                ->name('intakes.update');
            // Free-assessment (FA-… Lead) submission JSON for the "Open" modal.
            Route::get('/assessments/free/{id}/data', [ImmigrationController::class, 'freeAssessmentData'])
                ->where(['id' => '[0-9]+'])->name('assessments.free.data');
            // Visa Information Form export — A4 PDF download, an inline HTML
            // preview (for the download modal), and an editable Word (.doc).
            Route::get('/intakes/{type}/{id}/pdf', [ImmigrationController::class, 'downloadIntakePdf'])
                ->where(['type' => 'resident|work|student|visitor|family|free', 'id' => '[0-9]+'])
                ->name('intakes.pdf');
            Route::get('/intakes/{type}/{id}/preview', [ImmigrationController::class, 'previewIntakePdf'])
                ->where(['type' => 'resident|work|student|visitor|family|free', 'id' => '[0-9]+'])
                ->name('intakes.preview');
            Route::get('/intakes/{type}/{id}/word', [ImmigrationController::class, 'downloadIntakeWord'])
                ->where(['type' => 'resident|work|student|visitor|family|free', 'id' => '[0-9]+'])
                ->name('intakes.word');
            // Convert a visa-interest submission to an immigration case.
            // The {id} route param is Assessment.id (post-Phase-B
            // canonical). The controller also accepts a legacy
            // ResidentIntake.id for backward compat — any pre-Phase-B
            // bookmark that POSTed an intake id still resolves cleanly.
            Route::post('/assessments/{id}/convert-to-case', [ImmigrationController::class, 'convertAssessmentToCase'])->name('assessments.convert');
            // Delete an assessment submission (intake + paired Assessment).
            Route::delete('/assessments', [ImmigrationController::class, 'destroyIntake'])->name('assessments.destroy');
            // AI completeness/consistency review of an intake (internal, indicative
            // — NOT eligibility advice). GET returns the last stored review; POST
            // runs a fresh one. Immigration-staff only (route group).
            Route::get('/assessments/{type}/{id}/ai-review', [ImmigrationController::class, 'aiReviewShow'])
                ->where(['type' => 'resident|work|student|visitor|family', 'id' => '[0-9]+'])
                ->name('assessments.ai-review.show');
            Route::post('/assessments/{type}/{id}/ai-review', [ImmigrationController::class, 'aiReviewRun'])
                ->where(['type' => 'resident|work|student|visitor|family', 'id' => '[0-9]+'])
                ->name('assessments.ai-review.run');
            // Adviser saves edits to the drafted note + client email (they author it).
            Route::post('/assessments/{type}/{id}/ai-review/edit', [ImmigrationController::class, 'aiReviewEdit'])
                ->where(['type' => 'resident|work|student|visitor|family', 'id' => '[0-9]+'])
                ->name('assessments.ai-review.edit');
            // Attributed internal notes on an assessment (intake OR free-assessment
            // lead). Each note records its author so advisers see who noted what.
            Route::post('/assessments/{type}/{id}/notes', [ImmigrationController::class, 'assessmentNoteStore'])
                ->where(['type' => 'resident|work|student|visitor|family|free', 'id' => '[0-9]+'])
                ->name('assessments.notes.store');
            Route::get('/cases', [ImmigrationController::class, 'cases'])->name('cases');
            // Engagement + Invoice generation workspaces. Declared before the
            // /cases/{lead}/... routes below; both are single-segment literals
            // so they never collide with the {lead} binding.
            Route::get('/cases/engagement', [ImmigrationController::class, 'engagement'])->name('cases.engagement');
            Route::get('/cases/invoice', [ImmigrationController::class, 'invoice'])->name('cases.invoice');
            // INZ Forms console — generate/send INZ forms across all cases.
            Route::get('/cases/inz-forms', [ImmigrationController::class, 'caseInzForms'])->name('cases.inz-forms');
            // Create new case from the Cases page "Add new case" modal.
            Route::post('/cases', [ImmigrationController::class, 'storeCase'])->name('cases.store');
            // Edit an existing case (same modal fields as create).
            Route::post('/cases/{id}', [ImmigrationController::class, 'updateCase'])->name('cases.update');
            // Inline stage update from the Cases table — mirrors the
            // EducationController dashboard-field pattern.
            Route::post('/cases/{id}/stage', [ImmigrationController::class, 'updateCaseStage'])->name('cases.stage');
            // Decline outcome — moves to "Decline Visa" with an optional shared
            // decline letter, note, and client email.
            Route::post('/cases/{id}/decline', [ImmigrationController::class, 'declineVisa'])->name('cases.decline');
            // Positive/interim outcomes (Approved Visa / Interim Visa Issued /
            // Approved in Principle) — same modal as decline: shared document +
            // optional note; the client email is the configured stage automation.
            Route::post('/cases/{id}/outcome', [ImmigrationController::class, 'recordOutcome'])->name('cases.outcome');
            // Request for Information — moves to "Request for Information" with a
            // response deadline, attached RFI PDF(s) shared to the case, and the
            // configured RFI stage automation (PDFs attached to the email).
            Route::post('/cases/{id}/rfi/analyze', [ImmigrationController::class, 'analyzeRfi'])->name('cases.rfi.analyze');
            Route::post('/cases/{id}/rfi', [ImmigrationController::class, 'requestForInformation'])->name('cases.rfi');
            // Inline visa-type update from the Cases table.
            Route::post('/cases/{id}/visa', [ImmigrationController::class, 'updateCaseVisa'])->name('cases.visa');
            // Inline priority update from the Cases table's expanded row.
            Route::post('/cases/{id}/priority', [ImmigrationController::class, 'updateCasePriority'])->name('cases.priority');
            // Custody handoff (Build 12 phase 2). to_user_id = self is a claim.
            Route::post('/cases/{id}/handoff', [ImmigrationController::class, 'handoff'])->name('cases.handoff');

            // Build 11.D — purpose-built Case Profile page. The {lead} binding
            // is the Lead model; controller hard-404s when is_immigration_case
            // is false so this endpoint stays case-only. Sales leads continue
            // to use LeadController::show via /admin/leads/{id}.
            Route::get('/cases/{lead}/profile', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'show'])
                ->name('cases.profile');
            // Edit the applicant's personal details from the Case Profile "Personal" tab.
            Route::post('/cases/{lead}/personal', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'updatePersonal'])
                ->name('cases.personal');
            // On-demand AI note (OpenRouter) on what's missing from the assessment.
            Route::get('/cases/{lead}/assessment-ai-note', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'assessmentAiNote'])
                ->name('cases.assessment-ai-note');
            // AI "document vs case record" identity scan for the Personal tab.
            Route::post('/cases/{lead}/identity-scan', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'identityScan'])
                ->name('cases.identity-scan');

            // Build 12 phase 3 — case-assist findings. Dismiss carries a
            // required reason; re-evaluate queues a refresh (never on page load).
            Route::post('/cases/{lead}/findings/reevaluate', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'reevaluateFindings'])
                ->name('cases.findings.reevaluate');
            Route::post('/cases/{lead}/findings/{finding}/dismiss', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'dismissFinding'])
                ->name('cases.findings.dismiss');
            // Dismiss a whole rule's findings at once (the collapsed summary row).
            // Still per-item + evidence-scoped server-side — presentation grouping only.
            Route::post('/cases/{lead}/findings/group-dismiss', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'dismissFindingGroup'])
                ->name('cases.findings.group-dismiss');

            // Build 12 phase 4.5 — process chain. QC stamps + step completion
            // are procedural (no licence gate); the partner recommendation is
            // advice and gated inside the controller.
            Route::post('/cases/{lead}/steps/start', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'startProcess'])
                ->name('cases.steps.start');
            Route::post('/cases/{lead}/steps/{step}/complete', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'completeStep'])
                ->name('cases.steps.complete');
            Route::post('/cases/{lead}/steps/{step}/reactivate', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'reactivateStep'])
                ->name('cases.steps.reactivate');
            Route::post('/cases/{lead}/payment', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'recordPayment'])
                ->name('cases.payment');
            Route::post('/cases/{lead}/partner-recommendation', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'partnerRecommendation'])
                ->name('cases.partner-recommendation');

            // Build 12 phase 5 — advice-bearing attestations. Licence-gated in
            // the controller via AdviceBearingPolicy; the lodgement sign-off is
            // what completes step 12, not the upload.
            Route::post('/cases/{lead}/verdict', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'recordVerdict'])
                ->name('cases.verdict');
            Route::post('/cases/{lead}/lodgement-signoff', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'recordLodgementSignoff'])
                ->name('cases.lodgement-signoff');

            // Build 12 phase 6 — anchored threads. Posting requires an anchor
            // (validated in the controller); resolving is explicit and recorded.
            Route::post('/cases/{lead}/threads', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'storeThread'])
                ->name('cases.threads.store');
            Route::post('/cases/{lead}/threads/{thread}/resolve', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'resolveThread'])
                ->name('cases.threads.resolve');
            // Edit a comment's wording — author (or admin) only.
            Route::patch('/cases/{lead}/threads/{thread}', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'updateThread'])
                ->name('cases.threads.update');

            // Case financials — fees/invoice record + payment ledger (replaces the
            // spreadsheet money columns). Figures are human-entered; totals derived.
            Route::post('/cases/{lead}/financials', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'saveFinancials'])
                ->name('cases.financials.save');
            Route::post('/cases/{lead}/financials/payments', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'addFinancePayment'])
                ->name('cases.financials.payments.add');
            Route::delete('/cases/{lead}/financials/payments/{payment}', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'deleteFinancePayment'])
                ->name('cases.financials.payments.delete');
            // Generate / preview the case invoice from the fees + payment ledger.
            Route::post('/cases/{lead}/financials/invoice', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'generateInvoice'])
                ->name('cases.financials.invoice');
            Route::get('/cases/{lead}/financials/invoice/preview', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'previewInvoice'])
                ->name('cases.financials.invoice.preview');

            // Generate a filled INZ form (official PDF) as a draft on the case.
            Route::post('/cases/{lead}/inz-forms/{code}/generate', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'generateInzForm'])
                ->where('code', 'INZ[0-9]+')->name('cases.inz-forms.generate');

            // Send an INZ form to the client to fill in their portal.
            Route::post('/cases/{lead}/inz-forms/{code}/assign', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'assignInzForm'])
                ->where('code', 'INZ[0-9]+')->name('cases.inz-forms.assign');

            // Case dependants (children / partner) — sub-records + their documents.
            Route::post('/cases/{lead}/dependents', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'addDependent'])->name('cases.dependents.store');
            Route::get('/cases/{lead}/dependent-source', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'dependentSourceIdentity'])->name('cases.dependent-source');
            Route::put('/cases/{lead}/dependents/{dependent}', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'updateDependent'])->name('cases.dependents.update');
            Route::patch('/cases/{lead}/dependents/{dependent}/in-agreement', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'setDependentInAgreement'])->name('cases.dependents.in-agreement');
            Route::post('/cases/{lead}/dependents/{dependent}/open-case', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'openDependentCase'])->name('cases.dependents.open-case');
            Route::delete('/cases/{lead}/dependents/{dependent}', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'deleteDependent'])->name('cases.dependents.destroy');
            Route::post('/cases/{lead}/dependents/{dependent}/documents', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'uploadDependentDocument'])->name('cases.dependents.documents.store');
            Route::post('/cases/{lead}/dependents/{dependent}/documents/{document}/status', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'setDependentDocumentStatus'])->name('cases.dependents.documents.status');
            Route::post('/cases/{lead}/dependents/{dependent}/documents/{document}/note', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'setDependentDocumentNote'])->name('cases.dependents.documents.note');
            Route::delete('/cases/{lead}/dependents/{dependent}/documents/{document}', [\App\Http\Controllers\Immigration\CaseProfileController::class, 'deleteDependentDocument'])->name('cases.dependents.documents.destroy');

            // Build 11.D Phase 2 — Managed agreement endpoints. Each call
            // re-checks is_immigration_case + agreement<->lead ownership so
            // a cross-case URL guess still 404s. The route order matters:
            // /agreements/templates must come before /agreements/{agreement}
            // or the literal 'templates' would be captured as an Agreement id.
            Route::prefix('cases/{lead}/agreements')->name('cases.agreements.')->group(function () {
                Route::get('/', [\App\Http\Controllers\Immigration\AgreementController::class, 'index'])->name('index');
                Route::get('/templates', [\App\Http\Controllers\Immigration\AgreementController::class, 'templates'])->name('templates');
                Route::post('/', [\App\Http\Controllers\Immigration\AgreementController::class, 'generate'])->name('generate');
                Route::post('/{agreement}/send', [\App\Http\Controllers\Immigration\AgreementController::class, 'send'])->name('send');
                Route::get('/{agreement}/pdf', [\App\Http\Controllers\Immigration\AgreementController::class, 'downloadPdf'])->name('pdf');
                Route::post('/{agreement}/void', [\App\Http\Controllers\Immigration\AgreementController::class, 'void'])->name('void');
            });
            Route::get('/documents', [ImmigrationController::class, 'documents'])->name('documents');
            Route::get('/appointments', [ImmigrationController::class, 'appointments'])->name('appointments');
            Route::post('/appointments/{id}/resend-invoice', [ImmigrationController::class, 'resendInvoice'])->name('appointments.resend-invoice');
            Route::post('/availability', [ImmigrationController::class, 'saveAvailability'])->name('availability.save');

            // SETUP — visa types are managed through VisaTypeController so
            // the same logic (policy, audit, notifications) applies to both
            // admin staff and immigration managers/advisers.
            Route::get('/visa-types', [VisaTypeController::class, 'index'])->name('visa-types');
            Route::get('/visa-types/templates', [VisaTypeController::class, 'checklistTemplates'])->name('visa-types.templates');
            Route::post('/visa-types', [VisaTypeController::class, 'store'])->name('visa-types.store');
            Route::post('/visa-types/{visa_type}', [VisaTypeController::class, 'update'])->name('visa-types.update');
            Route::delete('/visa-types/{visa_type}', [VisaTypeController::class, 'destroy'])->name('visa-types.destroy');
            Route::get('/visa-types/{visa_type}/price-history', [VisaTypeController::class, 'priceHistory'])->name('visa-types.price-history');
            Route::get('/intakes', [ImmigrationController::class, 'intakes'])->name('intakes');
            Route::get('/inz-forms', [ImmigrationController::class, 'inzForms'])->name('inz-forms');
            // INZ form catalogue admin — upload official PDF per version, map fields,
            // record the human "still current" check.
            Route::post('/inz-forms', [ImmigrationController::class, 'inzStoreForm'])->name('inz-forms.store');
            Route::post('/inz-forms/{form}', [ImmigrationController::class, 'inzUpdateForm'])->name('inz-forms.update');
            Route::delete('/inz-forms/{form}', [ImmigrationController::class, 'inzDestroyForm'])->name('inz-forms.destroy');
            Route::post('/inz-forms/{form}/versions', [ImmigrationController::class, 'inzUploadVersion'])->name('inz-forms.versions.upload');
            Route::post('/inz-forms/versions/{version}/field-map', [ImmigrationController::class, 'inzSaveFieldMap'])->name('inz-forms.versions.field-map');
            Route::post('/inz-forms/versions/{version}/checked', [ImmigrationController::class, 'inzMarkChecked'])->name('inz-forms.versions.checked');
            Route::post('/inz-forms/versions/{version}/set-current', [ImmigrationController::class, 'inzSetCurrentVersion'])->name('inz-forms.versions.set-current');
            Route::delete('/inz-forms/versions/{version}', [ImmigrationController::class, 'inzDeleteVersion'])->name('inz-forms.versions.delete');
            // Visa categories (Setup → Category): CRUD + assign which visas belong.
            Route::post('/visa-categories', [ImmigrationController::class, 'categoryStore'])->name('visa-categories.store');
            Route::post('/visa-categories/{category}', [ImmigrationController::class, 'categoryUpdate'])->name('visa-categories.update');
            Route::delete('/visa-categories/{category}', [ImmigrationController::class, 'categoryDestroy'])->name('visa-categories.destroy');
            Route::post('/visa-categories/{category}/visas', [ImmigrationController::class, 'categoryAssignVisas'])->name('visa-categories.visas');
            Route::get('/checklist-templates', [ImmigrationController::class, 'checklistTemplates'])->name('checklist-templates');

            // Students — the same screen Education owns, rendered under this
            // portal's layout (see EducationController::studentsComponent).
            Route::get('/students', [EducationController::class, 'students'])->name('students');
            Route::post('/students/{id}/dashboard-field', [EducationController::class, 'updateStudentField'])->name('students.dashboard-field');
            Route::post('/students', [EducationController::class, 'storeStudent'])->name('students.store');
            Route::post('/students/{id}/update', [EducationController::class, 'updateStudent'])->name('students.update');
            Route::post('/students/{id}/destroy', [EducationController::class, 'destroyStudent'])->name('students.destroy');

            // Task Board — shared TaskBoardPage component (see resources/js/
            // components/task-board/TaskBoardPage.jsx).
            Route::get('/tasks', [ImmigrationController::class, 'tasks'])->name('tasks');
            Route::get('/client-documents', [ImmigrationController::class, 'clientDocuments'])->name('client-documents');

            // REPORTS
            Route::get('/reports', [ImmigrationController::class, 'reports'])->name('reports');
            Route::post('/reports/note', [ImmigrationController::class, 'saveReportNote'])->name('reports.note');

            // ACCOUNT
            Route::get('/profile', [ImmigrationController::class, 'profile'])->name('profile');
            Route::post('/profile', [ImmigrationController::class, 'updateProfile'])->name('profile.update');
            // Staff e-signature — drawn or uploaded, rendered on engagement docs.
            Route::post('/profile/signature', [ImmigrationController::class, 'saveSignature'])->name('profile.signature.save');
            Route::delete('/profile/signature', [ImmigrationController::class, 'deleteSignature'])->name('profile.signature.delete');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        });

        Route::middleware('portal:accommodation')->prefix('accommodation')->name('portal.accommodation.')->group(function () {
            Route::get('/dashboard', [AccommodationController::class, 'dashboard'])->name('dashboard');
            Route::get('/tasks', [AccommodationController::class, 'tasks'])->name('tasks');
            Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show');

            // Property viewing bookings from the public /accommodation page —
            // table + calendar, with per-booking status management.
            Route::get('/viewings', [AccommodationController::class, 'viewings'])->name('viewings');
            Route::post('/viewings/{id}/status', [AccommodationController::class, 'updateViewingStatus'])->name('viewings.status');
            Route::post('/viewings/{id}/resend', [AccommodationController::class, 'resendViewingConfirmation'])->name('viewings.resend');

            // Property CRUD (multipart: update is POST + _method=PUT). Public
            // listing fields + internal management record share one model.
            // Literal paths (create, export) are declared before /{property}.
            Route::get('/properties', [PropertyController::class, 'index'])->name('properties.index');
            Route::get('/properties/create', [PropertyController::class, 'create'])->name('properties.create');
            Route::get('/properties/export', [PropertyController::class, 'export'])->name('properties.export');
            Route::post('/properties', [PropertyController::class, 'store'])->name('properties.store');
            Route::get('/properties/{property}', [PropertyController::class, 'show'])->name('properties.show');
            Route::get('/properties/{property}/edit', [PropertyController::class, 'edit'])->name('properties.edit');
            Route::match(['POST', 'PUT'], '/properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
            Route::patch('/properties/{property}/archive', [PropertyController::class, 'archive'])->name('properties.archive');
            Route::delete('/properties/{property}', [PropertyController::class, 'destroy'])->name('properties.destroy');
            Route::delete('/properties/{property}/images/{image}', [PropertyController::class, 'destroyImage'])->name('properties.images.destroy');

            // Tenants — CRUD + lifecycle actions. Literal /export must precede
            // the resource so it isn't captured as /tenants/{tenant}.
            Route::get('/tenants/export', [TenantController::class, 'export'])->name('tenants.export');
            Route::post('/tenants/{tenant}/notice', [TenantController::class, 'markNoticeGiven'])->name('tenants.notice');
            Route::post('/tenants/{tenant}/vacate', [TenantController::class, 'markVacated'])->name('tenants.vacate');
            Route::post('/tenants/{tenant}/renew', [TenantController::class, 'markRenewed'])->name('tenants.renew');
            Route::post('/tenants/{tenant}/move', [TenantController::class, 'moveToProperty'])->name('tenants.move');
            Route::patch('/tenants/{tenant}/archive', [TenantController::class, 'archive'])->name('tenants.archive');
            Route::patch('/tenants/{tenant}/restore', [TenantController::class, 'restore'])->name('tenants.restore')->withTrashed();
            Route::resource('tenants', TenantController::class);

            // Onboarding pipeline (EOI submissions). Route NAMES stay
            // `applications.*` to match the model/controller; the canonical URL
            // is /onboarding, with /applications kept as a backwards-compat alias.
            Route::get('/onboarding', [EoiSubmissionController::class, 'index'])->name('onboarding');
            Route::get('/applications', [EoiSubmissionController::class, 'index'])->name('applications.index');
            Route::get('/applications/{submission}', [EoiSubmissionController::class, 'show'])->name('applications.show');
            Route::patch('/applications/{submission}/status', [EoiSubmissionController::class, 'updateStatus'])->name('applications.update-status');
            Route::post('/applications/{submission}/assign', [EoiSubmissionController::class, 'assignTo'])->name('applications.assign');
            Route::post('/applications/{submission}/link-property', [EoiSubmissionController::class, 'linkToProperty'])->name('applications.link-property');
            Route::post('/applications/{submission}/note', [EoiSubmissionController::class, 'addInternalNote'])->name('applications.note');
            Route::post('/applications/{submission}/convert', [EoiSubmissionController::class, 'convertToTenant'])->name('applications.convert');
            // Stream a tenant-uploaded pre-tenancy document (Valid ID / Visa).
            Route::get('/applications/{submission}/pre-tenancy/{kind}/download', [\App\Http\Controllers\PreTenancyFormController::class, 'download'])->name('applications.pre-tenancy.download');
            Route::delete('/applications/{submission}', [EoiSubmissionController::class, 'destroy'])->name('applications.destroy');

            // My Profile — staff account + e-signature (used as the Exalt admin
            // signatory on generated agreements).
            Route::get('/profile', function () {
                $me = auth()->user();

                return inertia('portal/accommodation/Profile', [
                    'user' => $me->only(['id', 'name', 'email', 'role']),
                    'signature' => [
                        'data_uri' => $me->signatureDataUri(),
                        'updated_at' => optional($me->signature_updated_at)?->toIso8601String(),
                    ],
                ]);
            })->name('profile');

            // ----------------------------------------------------------------
            // Forms — the standalone Pre-Tenancy list + the Agreements builder.
            // ----------------------------------------------------------------
            Route::get('/forms/pre-tenancy', [\App\Http\Controllers\Portal\PreTenancyFormsController::class, 'index'])->name('forms.pre-tenancy');
            Route::get('/forms/pre-tenancy/{form}', [\App\Http\Controllers\Portal\PreTenancyFormsController::class, 'show'])->name('forms.pre-tenancy.show');
            Route::get('/forms/pre-tenancy/{form}/{kind}/download', [\App\Http\Controllers\Portal\PreTenancyFormsController::class, 'download'])->name('forms.pre-tenancy.download');

            Route::get('/forms/agreements', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'index'])->name('forms.agreements');
            Route::post('/forms/agreements/prefill', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'prefill'])->name('forms.agreements.prefill');
            Route::post('/forms/agreements/preview', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'preview'])->name('forms.agreements.preview');
            Route::post('/forms/agreements/generate', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'generate'])->name('forms.agreements.generate');
            Route::get('/forms/agreements/{agreement}/download', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'download'])->name('forms.agreements.download');
            Route::post('/forms/agreements/{agreement}/resend', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'resend'])->name('forms.agreements.resend');
            Route::delete('/forms/agreements/{agreement}', [\App\Http\Controllers\Portal\AccommodationAgreementController::class, 'destroy'])->name('forms.agreements.destroy');

            // ----------------------------------------------------------------
            // Scaffolded sections — sidebar nav is live, real features ship
            // incrementally. Each points at the shared Placeholder page until
            // its controller/model/pages are built.
            // ----------------------------------------------------------------
            $stub = fn (string $title, string $description) => fn () => inertia('portal/accommodation/Placeholder', ['title' => $title, 'description' => $description]);

            // Work (Tenants + Onboarding + Calendar are real above; Task Tracker
            // uses the shared Task Board; the rest are scaffolded placeholders).
            // Calendar consolidates the old Viewers & Contract-End screens and
            // unifies events from several sources (events endpoint returns JSON).
            Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
            Route::get('/calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
            Route::post('/calendar/events', [CalendarController::class, 'storeCustomEvent'])->name('calendar.events.store');
            Route::patch('/calendar/events/{event}', [CalendarController::class, 'updateCustomEvent'])->name('calendar.events.update');
            Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroyCustomEvent'])->name('calendar.events.destroy');
            Route::get('/rent-utilities', [RentUtilitiesController::class, 'index'])->name('rent-utilities');
            Route::patch('/rent-utilities/tenants/{tenant}/payment', [RentUtilitiesController::class, 'savePayment'])->name('rent-utilities.payment');
            Route::patch('/rent-utilities/tenants/{tenant}/rent', [RentUtilitiesController::class, 'saveRentUtilities'])->name('rent-utilities.rent');
            Route::get('/payment-schedule', [PaymentScheduleController::class, 'index'])->name('payment-schedule');
            Route::get('/gas-delivery', [GasDeliveryController::class, 'index'])->name('gas-delivery');
            Route::get('/concerns', [App\Http\Controllers\ConcernController::class, 'index'])->name('concerns');
            Route::patch('/concerns/{concern}', [App\Http\Controllers\ConcernController::class, 'update'])->name('concerns.update');
            Route::get('/message-templates', [MessageTemplateController::class, 'index'])->name('message-templates');
            Route::post('/message-templates', [MessageTemplateController::class, 'store'])->name('message-templates.store');
            Route::patch('/message-templates/{template}', [MessageTemplateController::class, 'update'])->name('message-templates.update');
            Route::delete('/message-templates/{template}', [MessageTemplateController::class, 'destroy'])->name('message-templates.destroy');

            // Reports & Account ('/profile' is a real page defined above.)
            Route::get('/reports', $stub('Reports', 'Weekly, monthly, quarterly and custom reports.'))->name('reports');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        });

        // Finance portal — dashboard, cross-portal Task Board (department='finance'),
        // and the Accounts Receivable / Accounts Payable ledger.
        Route::middleware('portal:finance')->prefix('finance')->name('portal.finance.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Portal\FinanceController::class, 'dashboard'])->name('dashboard');
            Route::get('/invoice', [\App\Http\Controllers\Portal\FinanceController::class, 'invoice'])->name('invoice');
            Route::get('/tasks', [\App\Http\Controllers\Portal\FinanceController::class, 'tasks'])->name('tasks');

            // Accounts Receivable
            Route::get('/receivables', [\App\Http\Controllers\Portal\FinanceReceivableController::class, 'index'])->name('receivables');
            Route::post('/receivables', [\App\Http\Controllers\Portal\FinanceReceivableController::class, 'store'])->name('receivables.store');
            Route::put('/receivables/{receivable}', [\App\Http\Controllers\Portal\FinanceReceivableController::class, 'update'])->name('receivables.update');
            Route::delete('/receivables/{receivable}', [\App\Http\Controllers\Portal\FinanceReceivableController::class, 'destroy'])->name('receivables.destroy');
            Route::post('/receivables/{receivable}/payments', [\App\Http\Controllers\Portal\FinanceReceivableController::class, 'recordPayment'])->name('receivables.payments.store');
            Route::delete('/receivables/{receivable}/payments/{payment}', [\App\Http\Controllers\Portal\FinanceReceivableController::class, 'deletePayment'])->name('receivables.payments.destroy');

            // Accounts Payable
            Route::get('/payables', [\App\Http\Controllers\Portal\FinancePayableController::class, 'index'])->name('payables');
            Route::post('/payables', [\App\Http\Controllers\Portal\FinancePayableController::class, 'store'])->name('payables.store');
            Route::put('/payables/{payable}', [\App\Http\Controllers\Portal\FinancePayableController::class, 'update'])->name('payables.update');
            Route::delete('/payables/{payable}', [\App\Http\Controllers\Portal\FinancePayableController::class, 'destroy'])->name('payables.destroy');
            Route::post('/payables/{payable}/payments', [\App\Http\Controllers\Portal\FinancePayableController::class, 'recordPayment'])->name('payables.payments.store');
            Route::delete('/payables/{payable}/payments/{payment}', [\App\Http\Controllers\Portal\FinancePayableController::class, 'deletePayment'])->name('payables.payments.destroy');

            // Reports — income statement, aging, collections
            Route::get('/reports', [\App\Http\Controllers\Portal\FinanceReportController::class, 'index'])->name('reports');
        });

        // Lead Portal — external client-facing dashboard. Each lead-role user
        // is scoped to their own Lead record.
        Route::middleware(['portal:lead', \App\Http\Middleware\BlockLeadPortalPreviewWrites::class])->prefix('lead')->name('portal.lead.')->group(function () {
            Route::get('/dashboard', [App\Http\Controllers\LeadPortalController::class, 'dashboard'])->name('dashboard');
            Route::get('/submissions', [App\Http\Controllers\LeadPortalController::class, 'submissions'])->name('submissions');
            Route::get('/activities', [App\Http\Controllers\LeadPortalController::class, 'activities'])->name('activities');
            Route::get('/announcements', [App\Http\Controllers\LeadPortalController::class, 'announcements'])->name('announcements');

            // New sidebar sections — most are placeholders while the full
            // workflow ships incrementally.
            Route::get('/tracker', [App\Http\Controllers\LeadPortalController::class, 'tracker'])->name('tracker');
            // Requirements = the tracker's checklist view (sidebar item).
            Route::get('/requirements', [App\Http\Controllers\LeadPortalController::class, 'requirements'])->name('requirements');
            Route::get('/journey', [App\Http\Controllers\LeadPortalController::class, 'journey'])->name('journey');
            Route::get('/checklist', [App\Http\Controllers\LeadPortalController::class, 'checklist'])->name('checklist');
            Route::get('/visa-forms', [App\Http\Controllers\LeadPortalController::class, 'visaForms'])->name('visa-forms');
            Route::get('/visa-forms/{id}/preview', [App\Http\Controllers\LeadPortalController::class, 'visaFormPreview'])->name('visa-forms.preview');
            Route::post('/visa-forms/{id}', [App\Http\Controllers\LeadPortalController::class, 'visaFormSubmit'])->name('visa-forms.submit');
            // Visa Information Form — client generates from their assessment.
            Route::get('/forms', [App\Http\Controllers\LeadPortalController::class, 'forms'])->name('forms');
            Route::get('/visa-assessment', [App\Http\Controllers\LeadPortalController::class, 'visaAssessment'])->name('visa-assessment');
            Route::post('/visa-assessment', [App\Http\Controllers\LeadPortalController::class, 'updateAssessment'])->name('visa-assessment.update');
            Route::post('/vif', [App\Http\Controllers\LeadPortalController::class, 'generateVif'])->name('vif.generate');
            Route::get('/vif', [App\Http\Controllers\LeadPortalController::class, 'downloadVif'])->name('vif.download');
            // My Family — dependants the principal adds + their documents.
            Route::get('/family', [App\Http\Controllers\LeadPortalController::class, 'family'])->name('family');
            Route::post('/family', [App\Http\Controllers\LeadPortalController::class, 'familyStore'])->name('family.store');
            Route::put('/family/{id}', [App\Http\Controllers\LeadPortalController::class, 'familyUpdate'])->name('family.update');
            Route::delete('/family/{id}', [App\Http\Controllers\LeadPortalController::class, 'familyDelete'])->name('family.destroy');
            Route::post('/family/{id}/documents', [App\Http\Controllers\LeadPortalController::class, 'familyDocumentStore'])->name('family.documents.store');
            Route::get('/family/{id}/documents/{docId}', [App\Http\Controllers\LeadPortalController::class, 'familyDocumentDownload'])->name('family.documents.download');
            Route::delete('/family/{id}/documents/{docId}', [App\Http\Controllers\LeadPortalController::class, 'familyDocumentDelete'])->name('family.documents.destroy');
            Route::get('/appointments', [App\Http\Controllers\LeadPortalController::class, 'appointments'])->name('appointments');
            Route::get('/proposals', [App\Http\Controllers\LeadPortalController::class, 'proposals'])->name('proposals');
            Route::get('/agreements', [App\Http\Controllers\LeadPortalController::class, 'agreements'])->name('agreements');
            Route::get('/payments', [App\Http\Controllers\LeadPortalController::class, 'payments'])->name('payments');
            Route::get('/messages', [App\Http\Controllers\LeadPortalController::class, 'messages'])->name('messages');
            Route::get('/profile', [App\Http\Controllers\LeadPortalController::class, 'profile'])->name('profile');
            Route::post('/password', [App\Http\Controllers\LeadPortalController::class, 'updatePassword'])->name('password.update');
            Route::get('/settings', [App\Http\Controllers\LeadPortalController::class, 'settings'])->name('settings');
            Route::get('/documents', [LeadDocumentController::class, 'leadIndex'])->name('documents');
            Route::post('/documents/upload', [LeadDocumentController::class, 'leadUpload'])->name('documents.upload');
            Route::post('/documents/checklist/{key}/upload', [LeadDocumentController::class, 'leadChecklistUpload'])->name('documents.checklist.upload');
            Route::post('/documents/section/{key}/submit', [LeadDocumentController::class, 'leadSubmitSection'])->name('documents.section.submit');
            // Lead ticks "I've read and agreed to the Consultancy + English
            // Engagement Agreement terms" — sets / clears the timestamp.
            Route::post('/documents/agreements/acknowledge', [LeadDocumentController::class, 'leadAcknowledgeAgreements'])->name('documents.agreements.acknowledge');
            Route::get('/documents/{docId}/download', [LeadDocumentController::class, 'download'])->name('documents.download');
            // Client edits their own uploads from the portal (private, owner-scoped).
            Route::post('/documents/{docId}/replace', [LeadDocumentController::class, 'leadReplace'])->name('documents.replace');
            Route::delete('/documents/{docId}', [LeadDocumentController::class, 'leadDestroy'])->name('documents.destroy');
        });
    });
});

// Local-only email/template previews. The file is git-ignored and exists only
// on dev machines, so nothing here is committed or reachable on production —
// see routes/dev-previews.php.
if (app()->environment('local') && file_exists(__DIR__.'/dev-previews.php')) {
    require __DIR__.'/dev-previews.php';
}
