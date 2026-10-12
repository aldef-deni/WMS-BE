<?php

namespace App\Http\Controllers;

use App\Jobs\ExportReport;
use App\Models\Client;
use App\Models\ReportExport;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportJobController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->allowed('reports.export'), 403);

        return ['data' => ReportExport::where('user_id', $r->user()->id)->latest()->limit(10)->get()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['kind' => 'required|in:inventory,ledger,inbound,orders,charges,invoices', 'client_id' => 'nullable|integer|exists:clients,id', 'warehouse_id' => 'nullable|integer|exists:warehouses,id']);
        $u = $r->user();
        ExportService::query($u, $d['kind'], $d);
        abort_unless($u->inScope($d['client_id'] ?? null, $d['warehouse_id'] ?? null), 403);
        $job = ReportExport::create(['user_id' => $u->id, 'kind' => $d['kind'], 'filters' => collect($d)->except('kind')->all(), 'client_scope' => $u->hasRole('Super Admin') ? Client::pluck('id')->all() : $u->clients->pluck('id')->all(), 'warehouse_scope' => $u->hasRole('Super Admin') ? Warehouse::pluck('id')->all() : $u->warehouses->pluck('id')->all()]);
        ExportReport::dispatch($job->id)->afterCommit();
        Audit::record('report.queue', (string) $job->id, $d['client_id'] ?? null, $d['warehouse_id'] ?? null);

        return response()->json(['data' => $job], 202);
    }

    public function download(Request $r, int $id)
    {
        $job = ReportExport::where('user_id', $r->user()->id)->findOrFail($id);
        $u = $r->user();
        abort_unless($u->allowed('reports.export') && $u->allowed(ExportService::permission($job->kind)), 403);
        if (! $u->hasRole('Super Admin')) {
            abort_if(array_diff($job->client_scope, $u->clients->pluck('id')->all()) || array_diff($job->warehouse_scope, $u->warehouses->pluck('id')->all()), 403);
        }abort_unless($job->status === 'completed', 409, 'Ekspor belum selesai.');

        return Storage::disk('local')->download($job->path,$job->kind.'-export.csv');
    }
}
