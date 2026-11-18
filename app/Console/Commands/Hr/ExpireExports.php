<?php

namespace App\Console\Commands\Hr;

use App\Models\HrExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpireExports extends Command
{
    protected $signature = 'hr:expire-exports';

    protected $description = 'Delete expired private HR export files';

    public function handle(): int
    {
        HrExport::where('expires_at', '<', now())->where('status', '!=', 'expired')->select('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $x = HrExport::whereKey($row->id)->lockForUpdate()->first();
                    if ($x->storage_key) {
                        Storage::disk('hr_private')->delete($x->storage_key);
                    }$x->update(['storage_key' => null, 'status' => 'expired']);
                });
            }
        });

        return self::SUCCESS;
    }
}
