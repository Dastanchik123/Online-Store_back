<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportExportController extends Controller
{
    private const TYPES = ['products_pdf', 'debts_pdf'];

    // Ставит генерацию тяжёлого PDF-отчёта в очередь и сразу возвращает id —
    // фронт дальше поллит status() вместо того, чтобы держать открытый запрос
    // на время dompdf-рендера полного каталога/долгов.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'   => 'required|string|in:' . implode(',', self::TYPES),
            'params' => 'nullable|array',
        ]);

        $export = ReportExport::create([
            'uuid'    => (string) Str::uuid(),
            'type'    => $validated['type'],
            'params'  => $validated['params'] ?? [],
            'status'  => 'pending',
            'user_id' => auth()->id(),
        ]);

        GenerateReportExport::dispatch($export->id);

        return response()->json(['id' => $export->uuid, 'status' => $export->status], 201);
    }

    public function show(string $uuid)
    {
        $export = ReportExport::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'id'     => $export->uuid,
            'status' => $export->status,
            'error'  => $export->error,
            'ready'  => $export->status === 'done',
        ]);
    }

    public function download(string $uuid)
    {
        $export = ReportExport::where('uuid', $uuid)->where('status', 'done')->firstOrFail();

        if (! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            return response()->json(['message' => 'Файл не найден'], 404);
        }

        return Storage::disk('local')->download($export->file_path, $export->file_name);
    }
}
