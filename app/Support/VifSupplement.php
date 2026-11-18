<?php

namespace App\Support;

/** Additional LIA-reviewed questions. Existing answers keep their original keys. */
final class VifSupplement
{
    public static function schema(string $type): array
    {
        static $catalog;
        $catalog ??= json_decode(file_get_contents(resource_path('js/data/vifSupplementQuestions.json')), true, 512, JSON_THROW_ON_ERROR);

        return $catalog[$type] ?? [];
    }

    public static function rootKeys(string $type): array
    {
        return collect(self::schema($type))->flatMap(fn ($s) => $s['fields'])
            ->filter(fn ($f) => ($f['storage'] ?? '') === 'root')->pluck('key')->unique()->values()->all();
    }

    public static function rules(string $type, string $prefix = ''): array
    {
        $fields = collect(self::schema($type))->flatMap(fn ($s) => $s['fields'])->all();
        $keys = array_values(array_unique(array_column(array_filter($fields, fn ($f) => ($f['storage'] ?? '') !== 'root'), 'key')));
        $rules = [$prefix.'vif_answers' => ['nullable', 'array:'.implode(',', $keys)]];
        foreach ($fields as $f) {
            $key = $prefix.(($f['storage'] ?? '') === 'root' ? '' : 'vif_answers.').$f['key'];
            self::fieldRules($rules, $key, $f);
        }

        return $rules;
    }

    private static function fieldRules(array &$rules, string $key, array $f): void
    {
        $rules[$key] = match ($f['type']) {
            'list' => ['nullable', 'array', 'max:25'],
            'date' => ['nullable', 'date'],
            'select' => ['nullable', \Illuminate\Validation\Rule::in($f['options'])],
            default => ['nullable', 'string', 'max:'.($f['max'] ?? ($f['type'] === 'textarea' ? 5000 : 255))],
        };
        if ($f['type'] === 'list') {
            $rules[$key.'.*'] = ['array'];
            foreach ($f['fields'] as $child) {
                self::fieldRules($rules, $key.'.*.'.$child['key'], $child);
            }
        }
    }

    public static function values($intake, string $type): array
    {
        $out = ['vif_answers' => $intake->vif_answers ?? []];
        foreach (self::schema($type) as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['storage'] ?? '') === 'root') {
                    $out[$field['key']] = $intake->{$field['key']} ?? ($field['type'] === 'list' ? [] : '');
                }
            }
        }
        if ($type === 'family') {
            $out['applying_as'] = $intake->applying_as;
            $out['visa_type'] = $intake->visa_type;
        }

        return $out;
    }

    public static function sections(string $type, array $data): array
    {
        $sections = [];
        foreach (self::schema($type) as $section) {
            $answers = $data['vif_answers'] ?? [];
            $when = $section['when'] ?? null;
            if ($when) {
                $value = $answers[$when['key']] ?? '';
                if (isset($when['equals']) && $value !== $when['equals']) {
                    continue;
                }
                if (isset($when['not']) && $value === $when['not']) {
                    continue;
                }
            }
            if (($section['familyChildOnly'] ?? false) && (($data['applying_as'] ?? '') !== 'Dependent child' || ! preg_match('/residen/i', $data['visa_type'] ?? ''))) {
                continue;
            }
            $rows = [];
            foreach ($section['fields'] as $f) {
                $value = ($f['storage'] ?? '') === 'root' ? ($data[$f['key']] ?? null) : ($answers[$f['key']] ?? null);
                if ($f['type'] === 'list') {
                    foreach (is_array($value) ? $value : [] as $i => $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }
                        $rows[] = ['t' => 'sub', 'label' => $f['label'].' '.($i + 1)];
                        foreach ($f['fields'] as $child) {
                            $rows[] = ['q' => $child['label'], 'a' => self::entryValue($entry, $child)];
                        }
                    }
                    if (empty($value)) {
                        $rows[] = ['q' => $f['label'], 'a' => ''];
                    }
                } else {
                    $rows[] = ['q' => $f['label'], 'a' => self::scalar($value)];
                }
            }
            $sections[] = ['title' => $section['title'], 'rows' => $rows];
        }

        return $sections;
    }

    private static function entryValue(array $entry, array $field): string
    {
        foreach ([$field['key'], ...($field['aliases'] ?? [])] as $key) {
            if (isset($entry[$key]) && $entry[$key] !== '') {
                return self::scalar($entry[$key]);
            }
        }

        return '';
    }

    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
