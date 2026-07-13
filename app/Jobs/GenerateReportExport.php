<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Support\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 180;

    public function __construct(private int $reportExportId)
    {
    }

    public function handle(ReportExportService $service): void
    {
        $export = ReportExport::find($this->reportExportId);
        if (! $export) {
            return;
        }

        $export->update(['status' => 'processing']);

        try {
            $pdf = match ($export->type) {
                'products_pdf' => $service->productsPdf($export->params ?? []),
                'debts_pdf'    => $service->debtsPdf($export->params ?? []),
                default        => throw new \InvalidArgumentException("Неизвестный тип отчёта: {$export->type}"),
            };

            $fileName = $export->type . '_' . $export->uuid . '.pdf';
            $path     = 'report_exports/' . $fileName;
            Storage::disk('local')->put($path, $pdf);

            $export->update([
                'status'       => 'done',
                'file_path'    => $path,
                'file_name'    => $export->type . '.pdf',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ]);
            report($e);
        }
    }
}
