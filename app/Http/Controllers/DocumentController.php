<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionRequest;
use App\Http\Requests\DocumentRequest;
use App\Http\Resources\RecordResource;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Product;
use App\Services\Idempotency;
use App\Services\WorkflowService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $r)
    {
        $type = $r->validate(['type' => 'required|in:inbound,order,transfer,return,count,adjustment,manifest'])['type'];
        abort_unless($r->user()->allowed($type.'.read'), 403);
        $q = Document::visible($r->user())->where('type', $type)->with('client', 'warehouse', 'lines.product', 'tracking');
        if ($type === 'order') {
            switch ($r->input('queue')) {
                case 'picking':$q->whereIn('status', ['allocated', 'backorder', 'picking', 'partially_dispatched'])->whereHas('lines', fn ($q) => $q->whereColumn('allocated', '>', 'picked'));
                    if (! $r->user()->allowed('picking.assign')) {
                        $q->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $r->user()->id));
                    }break;
                case 'packing':$q->whereIn('status', ['picked', 'picking', 'packing', 'backorder', 'partially_dispatched'])->whereHas('lines', fn ($q) => $q->whereColumn('picked', '>', 'packed'));
                    break;
                case 'shipping':$q->whereIn('status', ['packed', 'manifested', 'dispatched', 'partially_dispatched']);
                    break;
            }
        }
        foreach (['client_id', 'warehouse_id', 'status'] as $filter) {
            if ($r->filled($filter)) {
                $q->where($filter, $r->input($filter));
            }
        }if ($r->filled('search')) {
            $q->where('number', 'like', '%'.substr($r->string('search'), 0, 100).'%');
        }$sort = in_array($r->input('sort'), ['number', 'status', 'created_at', 'priority']) ? $r->input('sort') : 'created_at';

        return RecordResource::collection($q->orderBy($sort, $r->input('direction') === 'asc' ? 'asc' : 'desc')->paginate(min(100, max(1, (int) $r->input('per_page', 15)))));
    }

    public function show(Request $r, int $id)
    {
        $d = Document::visible($r->user())->findOrFail($id);
        Gate::authorize('view', $d);
        $d->load('client', 'warehouse', 'lines.product', 'lines.allocations.balance.location', 'attachments', 'tracking', 'shipments');
        if ($d->type === 'count' && ! $r->user()->allowed('inventory.approve')) {
            foreach ($d->lines as $l) {
                $l->makeHidden(['snapshot', 'cutoff_ledger_id']);
            }
        }

return new RecordResource($d);
    }

    public function store(DocumentRequest $r, WorkflowService $workflow)
    {
        return new RecordResource(DB::transaction(fn () => $workflow->create($r->validated()), 5));
    }

    public function action(ActionRequest $r, int $id, string $action, WorkflowService $workflow)
    {
        $d = Document::visible($r->user())->findOrFail($id);
        Gate::authorize('update', [$d, $action]);
        $data = $r->validated();

        return Idempotency::run($data['idempotency_key'], 'document:'.$id.':'.$action, $data, fn () => ['data' => (new RecordResource($workflow->act($d, $action, $data, $data['idempotency_key'])))->resolve($r)]);
    }

    public function upload(Request $r, int $id)
    {
        $d = Document::visible($r->user())->findOrFail($id);
        Gate::authorize('view', $d);
        abort_unless($r->user()->allowed($d->type.'.write') || $r->user()->allowed('packing.execute') || $r->user()->allowed('return.inspect'), 403);
        $r->validate(['file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $file = $r->file('file');
        $path = $file->store('documents', 'local');

        return new RecordResource(Attachment::create(['document_id' => $d->id, 'client_id' => $d->client_id, 'warehouse_id' => $d->warehouse_id, 'path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()]));
    }

    public function download(Request $r, int $id)
    {
        $a = Attachment::visible($r->user())->findOrFail($id);
        Gate::authorize('view', $a->document ?? Document::findOrFail($a->document_id));

        return Storage::disk('local')->download($a->path, $a->name);
    }

    public function pdf(Request $r, int $id)
    {
        $d = Document::visible($r->user())->findOrFail($id);
        Gate::authorize('view', $d);
        $html = view('document', ['document' => $d->load('lines.product', 'client', 'warehouse')])->render();
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->loadHtml($html);
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$d->number.'.pdf"']);
    }

    public function import(Request $r, WorkflowService $workflow)
    {
        abort_unless($r->user()->allowed('order.write'), 403);
        $data = $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048', 'client_id' => 'required|integer|exists:clients,id', 'warehouse_id' => 'required|integer|exists:warehouses,id']);
        abort_unless($r->user()->inScope($data['client_id'], $data['warehouse_id']), 403);
        $handle = fopen($r->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header !== ['sku', 'quantity', 'recipient', 'address']) {
            abort(422, 'Header CSV wajib sku,quantity,recipient,address');
        }$lines = [];
        $recipient = '';
        $address = '';
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== 4 || ! ctype_digit($row[1]) || (int) $row[1] < 1 || count($lines) >= 200) {
                abort(422, 'Baris CSV tidak valid atau lebih dari 200.');
            }$product = Product::visible($r->user())->where('client_id', $data['client_id'])->where('sku', $row[0])->firstOrFail();
            $lines[] = ['product_id' => $product->id, 'quantity' => (int) $row[1]];
            $recipient = $row[2];
            $address = $row[3];
        }fclose($handle);
        if (! $lines) {
            abort(422,'CSV kosong.');
        }

return new RecordResource(DB::transaction(fn () => $workflow->create(['type' => 'order', 'client_id' => $data['client_id'], 'warehouse_id' => $data['warehouse_id'], 'lines' => $lines, 'meta' => ['recipient' => $recipient, 'address' => $address]]),5));
    }
}
