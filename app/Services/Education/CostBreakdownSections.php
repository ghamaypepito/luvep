<?php

namespace App\Services\Education;

class CostBreakdownSections
{
    public static function catalogue(): array
    {
        return json_decode(file_get_contents(resource_path('data/education-cost-sections.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Keep entered rows intact; only explicitly excluded other costs stay out of the total. */
    public static function layout(array $rows): array
    {
        $groups = [];
        $totals = ['amount' => 0, 'nzd' => 0, 'local' => 0];
        foreach ([['key' => '', 'label' => null], ...self::catalogue()] as $section) {
            $items = array_values(array_filter($rows, fn ($row) => ($row['section'] ?? '') === $section['key']));
            if ($items && $section['key'] !== 'other_costs') {
                $groups[] = ['heading' => $section['label'], 'rows' => $items];
                foreach ($items as $row) {
                    foreach (array_keys($totals) as $field) {
                        $totals[$field] += $row[$field];
                    }
                }
            }
        }

        return ['groups' => $groups, 'totals' => $totals,
            'other_rows' => array_values(array_filter($rows, fn ($row) => ($row['section'] ?? '') === 'other_costs'))];
    }
}
