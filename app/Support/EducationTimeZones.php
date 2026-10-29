<?php

namespace App\Support;

final class EducationTimeZones
{
    public static function identifiers(): array
    {
        return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
    }

    public static function preferred(): array
    {
        return ['Asia/Manila' => 'Philippine time (Asia/Manila)', 'Pacific/Auckland' => 'New Zealand time (Pacific/Auckland)', 'UTC' => 'Coordinated Universal Time (UTC)'];
    }

    public static function groups(): array
    {
        $groups = [];
        foreach (self::identifiers() as $zone) {
            if (isset(self::preferred()[$zone])) {
                continue;
            }$parts = explode('/', $zone);
            $region = array_shift($parts);
            $city = str_replace('_', ' ', implode(' / ', $parts));
            $groups[$region][$zone] = ($city ?: $zone).' ('.$zone.')';
        }
        ksort($groups);
        foreach ($groups as &$zones) {
            asort($zones, SORT_NATURAL | SORT_FLAG_CASE);
        }unset($zones);

        return $groups;
    }
}
