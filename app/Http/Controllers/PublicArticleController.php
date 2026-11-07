<?php

namespace App\Http\Controllers;

use App\Models\EditorialArticle;
use App\Support\Seo;
use Illuminate\View\View;

class PublicArticleController extends Controller
{
    public function index(Seo $seo): View
    {
        $articles = EditorialArticle::published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12, ['id', 'published_slug', 'published_at', 'published']);

        abort_if($articles->currentPage() > $articles->lastPage(), 404);

        return view('articles.index', [
            'articles' => $articles,
            'seo' => $seo->forArticleIndex($articles->currentPage()),
        ]);
    }

    public function show(Seo $seo, string $slug): View
    {
        $article = EditorialArticle::published()
            ->where('published_slug', $slug)
            ->firstOrFail(['published_slug', 'published_at', 'published_updated_at', 'published']);

        return view('articles.show', [
            'article' => $article,
            'content' => $article->published,
            'seo' => $seo->forArticle($article),
        ]);
    }
}
