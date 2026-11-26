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

    public static function answerKeys(string $type): array
    {
        return collect(self::schema($type))->flatMap(fn ($s) => $s['fields'])
            ->filter(fn ($f) => ($f['storage'] ?? '') !== 'root')->pluck('key')->unique()->values()->all();
    }

    public static function rules(string $type, string $prefix = ''): array
    {
        $fields = collect(self::schema($type))->flatMap(fn ($s) => $s['fields'])->all();
        $keys = self::answerKeys($type);
        $rules = [$prefix.'vif_answers' => ['nullable', 'array:'.implode(',', $keys)]];
        if ($type === 'family') {
            $rules[$prefix.'applying_as'] = ['sometimes', 'nullable', 'string', 'max:20'];
            $rules[$prefix.'visa_type'] = ['sometimes', 'nullable', 'string', 'max:120'];
        }
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
            'select' => ['nullable', \Illuminate\Validation\Rule::in($f['options'] === ['Yes', 'No'] ? ['Yes', 'No', '1', '0'] : $f['options'])],
            default => ['nullable', 'string', 'max:'.($f['max'] ?? ($f['type'] === 'textarea' ? 5000 : 255))],
        };
        if ($f['type'] === 'date' && ! str_contains($key, 'vif_answers.') && in_array($f['key'], ['start_date', 'finish_date', 'from', 'to'], true)) {
            $rules[$key] = ['nullable', function ($attribute, $value, $fail) {
                if (is_string($value) && preg_match('/^\d{4}(?:-(0[1-9]|1[0-2]))?$/', $value)) {
                    return; // retain historical year/month precision without inventing a day
                }
                if (\Illuminate\Support\Facades\Validator::make(['date' => $value], ['date' => 'date'])->fails()) {
                    $fail('The '.$attribute.' must be a valid date.');
                }
            }];
        }
        if ($f['type'] === 'list') {
            $rules[$key.'.*'] = ['array'];
            foreach ($f['fields'] as $child) {
                self::fieldRules($rules, $key.'.*.'.$child['key'], $child);
            }
        }
    }

    /** Normalize only catalogue-owned lists; leave unrelated fields and row columns intact. */
    public static function normalizeInput(string $type, array $data, bool $read = false): array
    {
        foreach (self::schema($type) as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['type'] !== 'list') {
                    continue;
                }
                if (($field['storage'] ?? '') === 'root') {
                    if (array_key_exists($field['key'], $data)) {
                        $data[$field['key']] = self::listRows($data[$field['key']], $field, $read);
                    }
                } elseif (is_array($data['vif_answers'] ?? null) && array_key_exists($field['key'], $data['vif_answers'])) {
                    $data['vif_answers'][$field['key']] = self::listRows($data['vif_answers'][$field['key']], $field, $read);
                }
            }
        }

        return $data;
    }

    private static function listRows($value, array $field, bool $read): mixed
    {
        if ($read && is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return $read ? [] : $value;
        }
        $keys = [];
        foreach ($field['fields'] as $child) {
            $keys = array_merge($keys, [$child['key']], $child['aliases'] ?? []);
        }
        $rows = array_intersect_key($value, array_flip($keys)) ? [$value] : array_values($value);
        if ($read) {
            $rows = array_values(array_filter($rows, 'is_array'));
        }
        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($field['fields'] as $child) {
                if (! array_key_exists($child['key'], $row)) {
                    foreach ($child['aliases'] ?? [] as $alias) {
                        if (array_key_exists($alias, $row) && $row[$alias] !== null && $row[$alias] !== '') {
                            $row[$child['key']] = $row[$alias];
                            break;
                        }
                    }
                }
                foreach ($child['aliases'] ?? [] as $alias) {
                    unset($row[$alias]);
                }
            }
        }
        unset($row);

        return $rows;
    }

    private static function visibleSections(string $type, array $data): array
    {
        $answers = is_array($data['vif_answers'] ?? null) ? $data['vif_answers'] : [];

        return array_values(array_filter(self::schema($type), function ($section) use ($answers, $data) {
            if (($section['familyChildOnly'] ?? false) && (($data['applying_as'] ?? '') !== 'Dependent child'
                || ! is_string($data['visa_type'] ?? null) || ! preg_match('/residen/i', $data['visa_type']))) {
                return false;
            }
            $when = $section['when'] ?? null;
            $value = $when ? ($answers[$when['key']] ?? '') : null;

            return ! $when || (isset($when['equals']) ? $value === $when['equals'] : $value !== $when['not']);
        }));
    }

    /** Remove inactive known answers on writes; do not rewrite unknown legacy siblings. */
    public static function activeData(string $type, array $data, bool $read = false, ?array $context = null): array
    {
        $visible = collect(self::visibleSections($type, $context ?? $data))->flatMap(fn ($s) => $s['fields'])
            ->filter(fn ($f) => ($f['storage'] ?? '') !== 'root')->pluck('key')->all();
        if (is_array($data['vif_answers'] ?? null)) {
            $data['vif_answers'] = $read
                ? array_intersect_key($data['vif_answers'], array_flip($visible))
                : array_diff_key($data['vif_answers'], array_flip(array_diff(self::answerKeys($type), $visible)));
        } elseif ($read) {
            $data['vif_answers'] = [];
        }

        return $data;
    }

    public static function readData(string $type, array $data): array
    {
        return self::activeData($type, self::normalizeInput($type, $data, read: true), read: true);
    }

    public static function values($intake, string $type): array
    {
        $data = self::readData($type, $intake->toArray());
        $out = ['vif_answers' => $data['vif_answers']];
        foreach (self::visibleSections($type, $data) as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['storage'] ?? '') === 'root') {
                    $out[$field['key']] = $data[$field['key']] ?? ($field['type'] === 'list' ? [] : '');
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
        $data = self::readData($type, $data);
        $sections = [];
        foreach (self::visibleSections($type, $data) as $section) {
            $answers = $data['vif_answers'];
            $rows = [];
            foreach ($section['fields'] as $f) {
                $value = ($f['storage'] ?? '') === 'root' ? ($data[$f['key']] ?? null) : ($answers[$f['key']] ?? null);
                if ($f['type'] === 'list') {
                    foreach (is_array($value) ? $value : [] as $i => $entry) {
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
            if (array_key_exists($key, $entry)) {
                return self::scalar($entry[$key]);
            }
        }

        return '';
    }

    private static function scalar($value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? (string) $value : '');
    }
}
