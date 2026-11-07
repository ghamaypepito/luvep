<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EditorialArticle;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class EditorialCalendarController extends Controller
{
    private const MAX_BODY_CHARACTERS = 200000;

    private const SUMMARY = ['id', 'title', 'planned_date', 'pillar', 'status', 'published_slug'];

    public function index(Request $request): Response
    {
        $month = $request->query('month', now('Asia/Manila')->format('Y-m'));
        abort_unless(is_string($month) && preg_match('/\A[1-9]\d{3}-(0[1-9]|1[0-2])\z/', $month), 422, 'Choose a valid calendar month.');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Manila');

        return Inertia::render('admin/editorial/ContentCalendar', [
            'month' => $month,
            'topics' => EditorialArticle::whereBetween('planned_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
                ->orderBy('planned_date')->orderBy('id')->get(self::SUMMARY),
            'undated' => EditorialArticle::whereNull('planned_date')->orderByDesc('updated_at')->get(self::SUMMARY),
            'canPublish' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function show(EditorialArticle $article): JsonResponse
    {
        return $this->respond($article);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $article = EditorialArticle::create($this->validateDraft($request));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That URL slug is already in use.']);
        }

        return $this->respond($article, 201);
    }

    public function update(Request $request, EditorialArticle $article): JsonResponse
    {
        try {
            return $this->withCurrentRevision($request, $article, function (EditorialArticle $current) use ($request) {
                $current->fill($this->validateDraft($request, $current));
                if ($current->isDirty()) {
                    $current->fill([
                        'revision' => $current->revision + 1,
                        'status' => 'draft',
                        'approved_revision' => null,
                        'approved_by' => null,
                    ])->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That URL slug is already in use.']);
        }
    }

    public function submit(Request $request, EditorialArticle $article): JsonResponse
    {
        return $this->withCurrentRevision($request, $article, function (EditorialArticle $current) {
            abort_unless($current->status === 'draft', 409, 'Only drafts can be submitted for review.');
            Validator::make($current->only(EditorialArticle::PUBLIC_FIELDS), [
                'summary' => 'required',
                'body_html' => 'required',
                'primary_keyword' => 'required',
                'meta_title' => 'required',
                'meta_description' => 'required',
                'author' => 'required',
                'image_url' => 'required',
                'image_alt' => 'required',
            ])->validate();
            $current->update(['status' => 'in_review']);
        });
    }

    public function approve(Request $request, EditorialArticle $article): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return $this->withCurrentRevision($request, $article, function (EditorialArticle $current) use ($request) {
            abort_unless($current->status === 'in_review', 409, 'Submit this revision for review first.');
            $current->update([
                'status' => 'approved',
                'approved_revision' => $current->revision,
                'approved_by' => $request->user()->id,
            ]);
        });
    }

    public function publish(Request $request, EditorialArticle $article): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return $this->withCurrentRevision($request, $article, function (EditorialArticle $current) {
            abort_unless($current->status === 'approved' && $current->approved_revision === $current->revision, 409, 'Approve this revision before publishing.');
            $current->update([
                'status' => 'published',
                'published_slug' => $current->published_slug ?? $current->slug,
                'published' => $current->only([...EditorialArticle::PUBLIC_FIELDS, 'pillar']),
                'published_at' => $current->published_at ?? now(),
                'published_updated_at' => now(),
            ]);
        });
    }

    private function withCurrentRevision(Request $request, EditorialArticle $article, callable $action): JsonResponse
    {
        $revision = (int) $request->validate(['revision' => 'required|integer|min:1'])['revision'];
        $current = DB::transaction(function () use ($article, $revision, $action) {
            $current = EditorialArticle::query()->lockForUpdate()->findOrFail($article->id);
            abort_unless($current->revision === $revision, 409, 'This article has changed. Reopen it before continuing.');
            $action($current);

            return $current;
        }, 3);

        return $this->respond($current);
    }

    private function validateDraft(Request $request, ?EditorialArticle $article = null): array
    {
        $slug = Rule::unique('editorial_articles', 'slug');
        if ($article) {
            $slug->ignore($article->id);
        }
        $draft = $request->validate([
            'title' => 'required|string|max:200',
            'slug' => ['required', 'string', 'max:180', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug],
            'planned_date' => 'nullable|date_format:Y-m-d',
            'pillar' => ['nullable', Rule::in(['Study', 'Immigration', 'Employment', 'Settlement'])],
            'summary' => 'nullable|string|max:600',
            'body_html' => 'nullable|string|max:'.self::MAX_BODY_CHARACTERS,
            'primary_keyword' => 'nullable|string|max:180',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:220',
            'author' => 'nullable|string|max:120',
            'image_url' => 'nullable|string|url:https|max:2000',
            'image_alt' => 'nullable|string|max:250',
        ]);
        if ($article?->published_slug && $draft['slug'] !== $article->published_slug) {
            throw ValidationException::withMessages(['slug' => 'The URL slug is fixed after first publication.']);
        }
        if (! empty($draft['body_html'])) {
            $config = (new HtmlSanitizerConfig)->withMaxInputLength(self::MAX_BODY_CHARACTERS * 4)
                ->allowLinkSchemes(['https', 'http', 'mailto'])->allowRelativeLinks();
            foreach (['p', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'li', 'strong', 'em', 's', 'u', 'br', 'hr', 'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as $element) {
                $config = $config->allowElement($element);
            }
            $config = $config->allowElement('a', ['href', 'title'])->dropElement('script')->dropElement('style');
            $safeHtml = (new HtmlSanitizer($config))->sanitize($draft['body_html']);
            $text = html_entity_decode(strip_tags($safeHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $draft['body_html'] = preg_match('/[^\s\p{Z}\p{Cf}]/u', $text) ? $safeHtml : null;
        }

        return $draft;
    }

    private function respond(EditorialArticle $article, int $status = 200): JsonResponse
    {
        return response()->json($article->only([
            ...self::SUMMARY, ...EditorialArticle::PUBLIC_FIELDS, 'slug', 'brief', 'revision',
        ]), $status);
    }
}
