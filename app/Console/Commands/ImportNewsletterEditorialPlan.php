<?php

namespace App\Console\Commands;

use App\Models\EditorialArticle;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

class ImportNewsletterEditorialPlan extends Command
{
    protected $signature = 'editorial:import-newsletter-plan {path? : Local JSON file with the private editorial plan}';

    protected $description = 'Import newsletter briefs without replacing existing drafts';

    public function handle(): int
    {
        $path = $this->argument('path') ?? storage_path('app/private/editorial/luvep-newsletter-plan-2026-11.json');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            $this->error('Provide a readable local plan file under 5 MB.');

            return self::FAILURE;
        }

        try {
            $topics = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The plan file is not valid JSON.');

            return self::FAILURE;
        }

        $validated = Validator::make(['topics' => $topics], [
            'topics' => 'required|array|list|min:1|max:200',
            'topics.*' => 'required|array',
            'topics.*.id' => ['required', 'string', 'max:32', 'regex:/\AEP-[0-9]+\z/', 'distinct'],
            'topics.*.title' => 'required|string|max:200',
            'topics.*.slug' => ['required', 'string', 'max:180', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'distinct'],
            'topics.*.date' => 'required|date_format:Y-m-d',
            'topics.*.pillar' => ['required', Rule::in(['Study', 'Immigration', 'Employment', 'Settlement'])],
            'topics.*.primary' => 'required|string|max:180',
            'topics.*.metaTitle' => 'required|string|max:100',
            'topics.*.metaDescription' => 'required|string|max:220',
            'topics.*.prompt' => 'required|string|max:16000',
            'topics.*.leadSubject' => 'sometimes|string|max:250',
            'topics.*.clientSubject' => 'sometimes|string|max:250',
            'topics.*.social' => 'sometimes|string|max:8000',
            'topics.*.feature' => 'sometimes|string|max:800',
            'topics.*.assetId' => 'sometimes|string|max:32',
            'topics.*.author' => 'sometimes|string|max:120',
            'topics.*.support' => 'sometimes|array|max:20',
            'topics.*.support.*' => 'string|max:180',
        ]);
        if ($validated->fails()) {
            $this->error($validated->errors()->first());

            return self::FAILURE;
        }

        try {
            $created = DB::transaction(function () use ($validated) {
                $count = 0;
                foreach ($validated->validated()['topics'] as $topic) {
                    $article = EditorialArticle::firstOrCreate(['topic_key' => $topic['id']], [
                        'title' => $topic['title'],
                        'slug' => $topic['slug'],
                        'planned_date' => $topic['date'],
                        'pillar' => $topic['pillar'],
                        'primary_keyword' => $topic['primary'],
                        'meta_title' => $topic['metaTitle'],
                        'meta_description' => $topic['metaDescription'],
                        'brief' => array_intersect_key($topic, array_flip([
                            'prompt', 'leadSubject', 'clientSubject', 'social', 'feature', 'assetId', 'author', 'support',
                        ])),
                    ]);
                    $count += (int) $article->wasRecentlyCreated;
                }

                return $count;
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('An article already uses a URL slug in this plan. No briefs were imported.');

            return self::FAILURE;
        }
        $this->info("Imported {$created} new editorial briefs.");

        return self::SUCCESS;
    }
}
