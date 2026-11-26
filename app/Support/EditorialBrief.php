<?php

namespace App\Support;

final class EditorialBrief
{
    public const FIELDS = [
        'prompt' => 'string|max:16000',
        'feature' => 'string|max:6000',
        'social' => 'string|max:8000',
        'leadSubject' => 'string|max:6000',
        'clientSubject' => 'string|max:6000',
        'assetId' => 'string|max:6000',
        'author' => 'string|max:120',
        'support' => 'array|max:20',
    ];

    public static function rules(bool $forGeneration = false): array
    {
        $presence = $forGeneration ? 'required' : 'nullable';
        $rules = ['brief' => $presence.'|array:'.implode(',', array_keys(self::FIELDS))];
        foreach (self::FIELDS as $field => $rule) {
            $rules['brief.'.$field] = 'nullable|'.$rule;
        }
        if ($forGeneration) {
            $rules['brief.prompt'] = 'required|'.self::FIELDS['prompt'].'|min:20';
        }
        $rules['brief.support.*'] = 'nullable|string|max:180';

        return $rules;
    }
}
