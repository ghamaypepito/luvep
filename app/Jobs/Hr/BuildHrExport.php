<?php

namespace App\Jobs\Hr;

use App\Models\HrExport;
use App\Services\Hr\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuildHrExport implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 120;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $x = HrExport::whereKey($this->exportId)->lockForUpdate()->first();
            if (! $x || ! in_array($x->status, ['queued', 'processing'], true)) {
                return;
            }$s = app(ReportService::class);
            if (! $s->authorized($x)) {
                $x->update(['status' => 'revoked']);

                return;
            }$stream = fopen('php://temp/maxmemory:2097152', 'w+');
            $key = 'exports/'.Str::uuid().'.csv';
            try {
                $header = false;
                foreach ($s->rows($x->requester, $x->type, $x->filters) as $row) {
                    if (! $header) {
                        fputcsv($stream, array_keys($row), escape: '');
                        $header = true;
                    }fputcsv($stream, array_map(fn ($v) => $s->csvCell($v), array_values($row)), escape: '');
                }if (! $header) {
                    fputcsv($stream, ['No records match these filters.'], escape: '');
                }if (! $s->authorized($x)) {
                    $x->update(['status' => 'revoked']);

                    return;
                }rewind($stream);
                Storage::disk('hr_private')->put($key, $stream);
                $x->update(['status' => 'ready', 'storage_key' => $key]);
            } catch (\Throwable $e) {
                Storage::disk('hr_private')->delete($key);
                throw $e;
            } finally {
                fclose($stream);
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        HrExport::whereKey($this->exportId)->where('status', 'queued')->update(['status' => 'failed']);
    }
}
