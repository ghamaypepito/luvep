<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EditorialArticle extends Model
{
    public const PUBLIC_FIELDS = [
        'title', 'summary', 'body_html', 'primary_keyword', 'meta_title',
        'meta_description', 'author', 'image_url', 'image_alt',
    ];

    protected $fillable = [
        ...self::PUBLIC_FIELDS, 'topic_key', 'planned_date', 'pillar', 'slug', 'brief',
        'revision', 'status', 'approved_revision', 'approved_by', 'published_slug',
        'published', 'published_at', 'published_updated_at',
    ];

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'published' => 'array',
            'revision' => 'integer',
            'approved_revision' => 'integer',
            'published_at' => 'datetime',
            'published_updated_at' => 'datetime',
        ];
    }

    public function canMove(): bool
    {
        return $this->status === 'draft' && $this->published_slug === null;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_slug')
            ->whereNotNull('published_at')
            ->whereNotNull('published');
    }
}
