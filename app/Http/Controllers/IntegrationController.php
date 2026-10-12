<?php

namespace App\Http\Controllers;

use App\Integrations\LocalEmailSimulator;
use App\Integrations\LocalMarketplaceSimulator;
use App\Models\Invoice;
use App\Services\Idempotency;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->allowed('master.read'), 403);

        return ['data' => [['name' => 'Marketplace', 'mode' => 'Simulator lokal', 'live' => false], ['name' => 'Kurir', 'mode' => 'Resi manual & simulator tracking', 'live' => false], ['name' => 'Email', 'mode' => 'Log lokal', 'live' => false]]];
    }

    public function marketplace(Request $r, WorkflowService $workflow)
    {
        abort_unless($r->user()->allowed('order.write'), 403);
        $d = $r->validate(['client_id' => 'required|integer|exists:clients,id', 'warehouse_id' => 'required|integer|exists:warehouses,id', 'product_id' => 'required|integer|exists:products,id', 'quantity' => 'required|integer|min:1|max:1000000', 'idempotency_key' => 'required|string|max:100']);

        return Idempotency::run($d['idempotency_key'], 'simulator:marketplace', $d, function () use ($d, $workflow) {
            $order = $workflow->create((new LocalMarketplaceSimulator)->orderPayload($d));
            $order->update(['meta' => array_merge($order->meta, ['source' => 'SIMULATOR LOKAL MARKETPLACE'])]);

            return ['data' => $order->fresh()->load('lines.product')->toArray(), 'simulated' => true];
        });
    }

    public function email(Request $r)
    {
        abort_unless($r->user()->allowed('billing.write'), 403);
        $d = $r->validate(['invoice_id' => 'required|integer', 'idempotency_key' => 'required|string|max:100']);
        $invoice = Invoice::visible($r->user())->findOrFail($d['invoice_id']);
        if (! $r->user()->hasRole('Super Admin')) {
            abort_if($invoice->charges()->whereNotIn('warehouse_id', $r->user()->warehouses->pluck('id'))->exists(), 403);
        }

return Idempotency::run($d['idempotency_key'], 'simulator:email', $d, fn () => (new LocalEmailSimulator)->simulate($invoice->client->email ?? 'demo@example.invalid', $invoice->number, 'Invoice lokal IDR '.$invoice->total));
    }
}
