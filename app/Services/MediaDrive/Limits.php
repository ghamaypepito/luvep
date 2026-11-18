<?php

namespace App\Services\MediaDrive;

class Limits
{
    public static function maxUploadMb(): int
    {
        $bytes = function (string $value): int {
            $value = trim($value);
            $n = (int) $value;
            if ($n <= 0) {
                return PHP_INT_MAX;
            }

            return $n * match (strtolower(substr($value, -1))) {
                'g' => 1024 ** 3,'m' => 1024 ** 2,'k' => 1024,default => 1
            };
        };

        return max(0, (int) floor(min(100 * 1024 ** 2, $bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size')) - 1024 ** 2) / 1024 ** 2));
    }
}
