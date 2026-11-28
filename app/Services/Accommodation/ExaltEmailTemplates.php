<?php

namespace App\Services\Accommodation;

use App\Models\MessageTemplate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ExaltEmailTemplates
{
    public const KEYS = [
        'exalt_client_01', 'exalt_client_02', 'exalt_client_03', 'exalt_client_04',
        'exalt_client_05', 'exalt_client_06', 'exalt_client_07', 'exalt_client_08',
    ];

    private const ACTIONS = [
        'exalt_client_02' => ['type' => 'viewing', 'buttons' => ['Book a Room / Property Viewing']],
        'exalt_client_03' => ['type' => 'attendance', 'buttons' => ['Confirm Attendance', 'Decline Attendance']],
        'exalt_client_04' => ['type' => 'pre_tenancy', 'buttons' => ['Complete the Pre-Tenancy Form']],
        'exalt_client_05' => ['type' => 'agreement', 'buttons' => ['Review and Sign Agreement']],
        'exalt_client_06' => ['type' => 'signed_agreement', 'buttons' => ['Download Signed Agreement']],
        'exalt_client_07' => ['type' => 'invoice', 'buttons' => ['View or Download Invoice']],
        'exalt_client_08' => ['type' => 'welcome', 'buttons' => []],
    ];

    public const FIELD_PATTERN = '/\[([^\[\]\r\n]+)\]/u';

    public static function sort(Collection $templates): Collection
    {
        $positions = array_flip(self::KEYS);

        return $templates->sortBy(fn (MessageTemplate $template) => $positions[$template->key] ?? count($positions))->values();
    }

    public function metadata(MessageTemplate $template): array
    {
        $action = self::ACTIONS[$template->key] ?? ['type' => '', 'buttons' => []];
        $fields = [];
        $amount = 0;
        preg_match_all(self::FIELD_PATTERN, $template->email_subject."\n".$template->email_body, $matches);

        foreach ($matches[1] as $label) {
            if (in_array($label, $action['buttons'], true)) {
                continue;
            }
            $key = $label === 'Amount' ? (++$amount === 1 ? 'Bond amount' : 'Deposit amount') : $label;
            $fields[$key] = [
                'key' => $key,
                'optional' => str_starts_with($key, 'Optional ') || $key === 'Amount and Dates Covered',
            ];
        }

        return ['fields' => array_values($fields), 'action' => $action['type']];
    }

    public function render(MessageTemplate $template, array $input, array $recordFields, array $buttons): array
    {
        $fields = [];
        foreach ($this->metadata($template)['fields'] as $field) {
            $key = $field['key'];
            $value = trim((string) ($recordFields[$key] ?? $input[$key] ?? ''));
            if ($value === '' && ! $field['optional']) {
                throw ValidationException::withMessages(['fields' => "Complete the {$key} field."]);
            }
            $fields[$key] = $value;
        }

        if ($template->email_body) {
            foreach (self::ACTIONS[$template->key]['buttons'] ?? [] as $button) {
                if (! str_contains($template->email_body, '['.$button.']') || ! isset($buttons[$button])) {
                    throw ValidationException::withMessages(['template_id' => 'This template needs its original action placeholders.']);
                }
            }
        }

        $subject = preg_replace_callback(self::FIELD_PATTERN, fn ($match) => $fields[$match[1]] ?? '', $template->email_subject);
        if ($subject === '' || mb_strlen($subject) > 255 || str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw ValidationException::withMessages(['fields' => 'Enter a subject of no more than 255 characters on one line.']);
        }

        $amount = 0;
        $body = preg_replace_callback(self::FIELD_PATTERN, function ($match) use ($fields, $buttons, &$amount) {
            $label = $match[1];
            if (isset($buttons[$label])) {
                return '<a style="display:inline-block;padding:10px 16px;background:#17443a;color:#ffffff;text-decoration:none;border-radius:5px" href="'.e($buttons[$label]).'">'.e($label).'</a>';
            }

            $key = $label === 'Amount' ? (++$amount === 1 ? 'Bond amount' : 'Deposit amount') : $label;

            return nl2br(e($fields[$key] ?? ''), false);
        }, $template->email_body);

        return [
            'subject' => $subject,
            'body' => '<!doctype html><html lang="en"><meta charset="utf-8"><body style="font:15px Arial,sans-serif;line-height:1.5">'
                .$body.'</body></html>',
        ];
    }
}
