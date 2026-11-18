<?php

namespace App\Models;

use App\Services\Ghl\GhlSocialReader;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Model;

class EditorialSocialDelivery extends Model
{
    protected $fillable = [
        'editorial_article_id', 'account_id', 'payload', 'status',
        'waiting_for_connection', 'provider_post_id',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'waiting_for_connection' => 'boolean'];
    }

    public static function queueFacebook(EditorialArticle $article, Seo $seo): void
    {
        $content = $article->published;
        $link = $seo->canonicalFor(route('articles.show', $article->published_slug, absolute: false));

        self::create([
            'editorial_article_id' => $article->id,
            'account_id' => GhlSocialReader::ACCOUNT,
            'payload' => [
                'summary' => "{$content['title']}\n\n{$content['summary']}\n\n{$link}",
                'ogTagsDetails' => [
                    'metaLink' => $link,
                    'metaImage' => $content['image_url'],
                    'ogTitle' => $content['title'],
                    'ogDescription' => $content['meta_description'],
                ],
            ],
        ]);
    }
}
