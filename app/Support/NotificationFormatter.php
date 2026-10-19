<?php

namespace App\Support;

/**
 * Centralized notification type → UI mapping. Turns a stored database
 * notification (type + data) into the icon / color / title / body / url
 * the frontend renders. Add a `case` here when introducing a new
 * notification type; unknown types fall back to a generic shape so the
 * UI never crashes on an unmapped notification.
 *
 * `icon` values are lucide-react icon names; `color` is a semantic token
 * the frontend maps to Tailwind classes.
 */
class NotificationFormatter
{
    /**
     * @param  array{id?:string,type?:string,data?:array,read_at?:mixed,created_at?:mixed}  $notification
     *                                                                                                     A serialized notification (or an array with `type` short name + `data`).
     * @return array{icon:string,color:string,title:string,body:string,url:?string,type:string}
     */
    public static function format(array $notification): array
    {
        $type = class_basename($notification['type'] ?? 'Notification');
        $data = $notification['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        $url = $data['link'] ?? $data['url'] ?? null;

        $meta = match ($type) {
            'HrActionRequired' => ['icon' => 'Bell', 'color' => 'emerald', 'title' => $data['title'] ?? 'Luvep HR update'],
            'VisaTypePriceChanged' => [
                'icon' => 'DollarSign',
                'color' => 'amber',
                'title' => $data['title'] ?? 'Visa pricing updated',
            ],
            'LeadAssignedToYou' => [
                'icon' => 'UserPlus',
                'color' => 'blue',
                'title' => $data['title'] ?? 'Lead assigned to you',
            ],
            'DocumentSubmittedForReview' => [
                'icon' => 'FileText',
                'color' => 'emerald',
                'title' => $data['title'] ?? 'Document submitted for review',
            ],
            'LeadInfoUpdated' => [
                'icon' => 'UserPen',
                'color' => 'blue',
                'title' => $data['title'] ?? 'Lead updated their details',
            ],
            'LeadConvertedToDepartment' => [
                'icon' => 'ArrowRightLeft',
                'color' => 'blue',
                'title' => $data['title'] ?? 'New department lead',
            ],
            'NewRegistrationReceived' => [
                'icon' => 'UserPlus',
                'color' => 'emerald',
                'title' => $data['title'] ?? 'New registration',
            ],
            'TaskAssigned' => [
                'icon' => 'CheckSquare',
                'color' => 'amber',
                'title' => $data['title'] ?? 'New task assigned to you',
            ],
            'TicketSubmitted' => [
                'icon' => 'Ticket',
                'color' => 'amber',
                'title' => $data['title'] ?? 'New system request',
            ],
            'TicketUpdated' => [
                'icon' => 'Ticket',
                'color' => 'blue',
                'title' => $data['title'] ?? 'Your request was updated',
            ],
            'AiCriticalLeadAlert' => [
                'icon' => 'AlertTriangle',
                'color' => 'red',
                'title' => $data['title'] ?? 'AI flagged a lead as critical',
            ],
            'AiCriticalCaseAlert' => [
                'icon' => 'AlertTriangle',
                'color' => 'red',
                'title' => $data['title'] ?? 'AI flagged a case as critical',
            ],
            'AgreementSigned' => [
                'icon' => 'FileSignature',
                'color' => 'emerald',
                'title' => $data['title'] ?? 'Agreement signed',
            ],
            'AgreementSignedNotification' => [
                'icon' => 'FileSignature',
                'color' => 'emerald',
                'title' => $data['title'] ?? 'Agreement signed',
            ],
            default => [
                'icon' => 'Bell',
                'color' => 'gray',
                // Fall back to a humanized class name as the title.
                'title' => $data['title'] ?? self::humanize($type),
            ],
        };

        // Body: prefer an explicit body, else a compact dump of whatever
        // scalar data exists so nothing is ever blank.
        $body = $data['body'] ?? self::fallbackBody($data);

        return [
            'type' => $type,
            'icon' => $meta['icon'],
            'color' => $meta['color'],
            'title' => $meta['title'],
            'body' => $body,
            'url' => $url,
        ];
    }

    private static function fallbackBody(array $data): string
    {
        // No `body` key on a known/unknown type — show whatever data we
        // have rather than nothing.
        $bits = array_filter($data, fn ($v) => is_scalar($v));

        return $bits ? implode(' · ', array_map(
            fn ($k, $v) => "{$k}: {$v}",
            array_keys($bits),
            array_values($bits),
        )) : json_encode($data);
    }

    private static function humanize(string $type): string
    {
        // "LeadAssignedToYou" → "Lead Assigned To You"
        return trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $type));
    }
}
