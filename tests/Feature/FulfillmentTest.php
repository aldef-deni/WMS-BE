<?php

namespace Tests\Feature;

use App\Jobs\AccrueStorage;
use App\Jobs\ExportReport;
use App\Models\Attachment;
use App\Models\Balance;
use App\Models\Catalog;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfillmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('aldef_fulfillment_test', DB::connection()->getDatabaseName(), 'Tests must use the dedicated test schema.');
        $this->seed(DatabaseSeeder::class);
        $this->as('admin');
    }

    private function as(string $name): User
    {
        $u = User::where('email', $name.'@aldef.local')->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    private function fixture(int $quantity = 5): array
    {
        $client = Client::first();
        $warehouse = Warehouse::first();
        $sku = 'TEST-'.bin2hex(random_bytes(5));
        $product = Product::create(['client_id' => $client->id, 'sku' => $sku, 'barcode' => $sku, 'name' => 'Test item', 'unit' => 'pcs', 'weight' => '0.500', 'minimum_stock' => 1]);
        $location = Location::where('warehouse_id', $warehouse->id)->where('type', 'storage')->first();
        $in = $this->postJson('/api/v1/documents', ['type' => 'inbound', 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'batch' => 'TEST']]])->assertSuccessful()->json('data');

        return compact('client', 'warehouse', 'product', 'location', 'in');
    }

    private function action(array $doc, string $action, array $data = [], ?string $key = null)
    {
        return $this->postJson('/api/v1/documents/'.$doc['id'].'/actions/'.$action, $data + ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function stocked(int $quantity = 5): array
    {
        $f = $this->fixture($quantity);
        $line = $f['in']['lines'][0];
        $this->action($f['in'], 'receive', ['line_id' => $line['id'], 'quantity' => $quantity, 'scan' => $f['product']->barcode])->assertSuccessful();
        $this->action($f['in'], 'putaway', ['line_id' => $line['id'], 'quantity' => $quantity, 'location_id' => $f['location']->id])->assertSuccessful();

        return $f;
    }

    private function order(array $f, int $qty = 1): array
    {
        return $this->postJson('/api/v1/documents', ['type' => 'order', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'meta' => ['recipient' => 'Test recipient', 'address' => 'Jakarta'], 'lines' => [['product_id' => $f['product']->id, 'quantity' => $qty, 'batch' => 'TEST']]])->assertSuccessful()->json('data');
    }

    public function test_end_to_end_receiving_picking_manifest_dispatch_billing(): void
    {
        $f = $this->stocked(5);
        $o = $this->order($f, 3);
        $l = $o['lines'][0];
        $before = Balance::where('product_id', $f['product']->id)->sum('quantity');
        $this->action($o, 'reserve')->assertJsonPath('data.status', 'allocated');
        $this->action($o, 'pick', ['line_id' => $l['id'], 'quantity' => 3, 'scan' => $f['product']->barcode, 'location_code' => $f['location']->code])->assertJsonPath('data.status', 'picked');
        $this->assertEquals($before, Balance::where('product_id', $f['product']->id)->sum('quantity'), 'Picking must preserve warehouse physical stock.');
        $box = Catalog::where('kind', 'packaging')->first();
        $courier = Catalog::where('kind', 'courier')->first();
        $this->action($o, 'pack', ['line_id' => $l['id'], 'quantity' => 4, 'scan' => $f['product']->barcode, 'packaging_id' => $box->id, 'actual_weight' => '1.5'])->assertUnprocessable();
        $this->action($o, 'pack', ['line_id' => $l['id'], 'quantity' => 3, 'scan' => $f['product']->barcode, 'packaging_id' => $box->id, 'actual_weight' => '1.5'])->assertJsonPath('data.status', 'packed');
        $this->action($o, 'manifest', ['courier_id' => $courier->id, 'tracking_number' => 'TEST-TRACK', 'manifest_number' => 'TEST-MANIFEST'])->assertJsonPath('data.status', 'manifested');
        $this->action($o, 'dispatch', ['scan' => 'TEST-TRACK'], 'dispatch-repeat')->assertJsonPath('data.status', 'dispatched');
        $this->action($o, 'dispatch', ['scan' => 'TEST-TRACK'], 'dispatch-repeat')->assertSuccessful();
        $this->assertEquals(2, Balance::where('product_id', $f['product']->id)->sum('quantity'));
        $this->assertEquals(0, Balance::where('product_id', $f['product']->id)->sum('reserved'));
        $request = ['client_id' => $f['client']->id, 'period_start' => now()->subDays(7)->toDateString(), 'period_end' => today('Asia/Jakarta')->toDateString(), 'due_date' => today('Asia/Jakarta')->addDays(14)->toDateString(), 'idempotency_key' => 'invoice-repeat'];
        $invoice = $this->postJson('/api/v1/invoices', $request)->assertSuccessful()->json('data');
        $this->postJson('/api/v1/invoices', $request)->assertJsonPath('data.id', $invoice['id']);
        $this->assertEquals(4, Charge::where('document_id', $o['id'])->count());
        $this->assertEquals(1, LedgerEntry::where('document_id', $o['id'])->where('event', 'dispatch')->count());
        $this->assertGreaterThan(0, (float) $invoice['total']);
        $this->get('/api/v1/documents/'.$o['id'].'/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_role_and_cross_client_isolation_in_detail_export_and_attachment(): void
    {
        $other = Client::where('code', 'SORA')->first();
        $doc = Document::where('client_id', $other->id)->where('type', 'order')->first();
        $attachment = Attachment::create(['document_id' => $doc->id, 'client_id' => $other->id, 'warehouse_id' => $doc->warehouse_id, 'path' => 'secret.pdf', 'name' => 'secret.pdf', 'mime' => 'application/pdf']);
        $this->as('client');
        $this->getJson('/api/v1/documents/'.$doc->id)->assertNotFound();
        $this->get('/api/v1/attachments/'.$attachment->id)->assertNotFound();
        $this->get('/api/v1/documents/'.$doc->id.'/pdf')->assertNotFound();
        $list = $this->getJson('/api/v1/documents?type=order')->assertOk()->json('data');
        $this->assertNotEmpty($list);
        foreach ($list as $row) {
            $this->assertNotEquals($other->id, $row['client_id']);
        }
        $csv = $this->get('/api/v1/exports/orders')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($doc->number, $csv);
        $this->getJson('/api/v1/master/users')->assertForbidden();
        $this->postJson('/api/v1/master/roles', ['name' => 'Admin escape', 'permissions' => ['users.manage']])->assertForbidden();
        $this->as('viewer');
        $p = Product::where('client_id', Client::where('code', 'VRD')->value('id'))->first();
        $this->postJson('/api/v1/documents', ['type' => 'order', 'client_id' => $p->client_id, 'warehouse_id' => $doc->warehouse_id, 'lines' => [['product_id' => $p->id, 'quantity' => 1]]])->assertForbidden();
    }

    public function test_warehouse_scope_is_enforced(): void
    {
        $f = $this->fixture();
        $u = $this->as('inventory');
        $first = Warehouse::first();
        $u->warehouses()->sync([$first->id]);
        $u->unsetRelation('warehouses');
        $other = Warehouse::where('id', '!=', $first->id)->first();
        $this->postJson('/api/v1/documents', ['type' => 'transfer', 'client_id' => $f['client']->id, 'warehouse_id' => $other->id, 'lines' => [['product_id' => $f['product']->id, 'quantity' => 1]]])->assertForbidden();
        $this->getJson('/api/v1/master/locations')->assertOk()->assertJsonMissing(['warehouse_id' => $other->id]);
    }

    public function test_last_unit_is_not_allocated_twice_and_cancel_releases(): void
    {
        $f = $this->stocked(1);
        $a = $this->order($f);
        $b = $this->order($f);
        $this->action($a, 'reserve')->assertJsonPath('data.status', 'allocated');
        $this->action($b, 'reserve')->assertJsonPath('data.status', 'backorder');
        $this->assertEquals(1, Balance::where('product_id', $f['product']->id)->sum('reserved'));
        $this->action($a, 'cancel')->assertJsonPath('data.status', 'cancelled');
        $this->action($b, 'reserve')->assertJsonPath('data.status', 'allocated');
    }

    public function test_partial_receiving_and_idempotent_retries(): void
    {
        $f = $this->fixture(5);
        $line = $f['in']['lines'][0];
        $data = ['line_id' => $line['id'], 'quantity' => 2, 'scan' => $f['product']->barcode];
        $this->action($f['in'], 'receive', $data, 'receive-repeat')->assertSuccessful();
        $this->action($f['in'], 'receive', $data, 'receive-repeat')->assertSuccessful();
        $this->action($f['in'], 'receive', array_replace($data, ['quantity' => 3]), 'receive-repeat')->assertUnprocessable();
        $this->assertEquals(2, Balance::where('product_id', $f['product']->id)->sum('quantity'));
        $this->action($f['in'], 'putaway', ['line_id' => $line['id'], 'quantity' => 2, 'location_id' => $f['location']->id])->assertSuccessful();
        $this->action($f['in'], 'receive', array_replace($data, ['quantity' => 4]))->assertUnprocessable();
        $this->action($f['in'], 'receive', array_replace($data, ['quantity' => 3]))->assertSuccessful();
    }

    public function test_short_pick_and_safe_return_from_staging(): void
    {
        $f = $this->stocked(3);
        $o = $this->order($f, 3);
        $line = $o['lines'][0];
        $this->action($o, 'reserve')->assertSuccessful();
        $this->action($o, 'short-pick', ['line_id' => $line['id'], 'quantity' => 1, 'reason' => 'Bin empty'])->assertJsonPath('data.status', 'backorder');
        $this->assertEquals(2, Balance::where('product_id', $f['product']->id)->sum('reserved'));
        $this->action($o, 'pick', ['line_id' => $line['id'], 'quantity' => 2, 'scan' => $f['product']->barcode, 'location_code' => $f['location']->code])->assertSuccessful();
        $this->action($o, 'cancel')->assertUnprocessable();
        $this->action($o, 'return-picked')->assertSuccessful();
        $this->action($o, 'cancel')->assertSuccessful();
        $this->assertEquals(3, Balance::where('product_id', $f['product']->id)->sum('quantity'));
        $this->assertEquals(0, Balance::where('product_id', $f['product']->id)->sum('reserved'));
    }

    public function test_ledger_matches_all_balances_and_reservations(): void
    {
        $f = $this->stocked(8);
        $o = $this->order($f, 4);
        $this->action($o, 'reserve')->assertSuccessful();
        $this->action($o, 'cancel')->assertSuccessful();
        foreach (Balance::all() as $b) {
            $this->assertEquals($b->quantity, LedgerEntry::where('balance_id', $b->id)->sum('quantity_delta'));
            $this->assertEquals($b->reserved, LedgerEntry::where('balance_id', $b->id)->sum('reserved_delta'));
            $this->assertGreaterThanOrEqual(0, $b->quantity - $b->reserved);
        }
    }

    public function test_return_inspection_is_limited_to_dispatched_units(): void
    {
        $order = Document::where('type', 'order')->where('status', 'dispatched')->with('lines.product')->first();
        $line = $order->lines[0];
        $r = $this->postJson('/api/v1/documents', ['type' => 'return', 'client_id' => $order->client_id, 'warehouse_id' => $order->warehouse_id, 'reference_id' => $order->id, 'lines' => [['product_id' => $line->product_id, 'quantity' => $line->dispatched, 'batch' => $line->batch]]])->assertSuccessful()->json('data');
        $args = ['line_id' => $r['lines'][0]['id'], 'quantity' => 1, 'scan' => $line->product->barcode, 'condition' => 'quarantine'];
        $this->action($r, 'inspect', $args, 'return-repeat')->assertSuccessful();
        $this->action($r, 'inspect', $args, 'return-repeat')->assertSuccessful();
        $this->assertEquals(1, $line->fresh()->returned);
        $this->assertEquals(1, Balance::where('product_id', $line->product_id)->where('condition', 'quarantine')->sum('quantity'));
    }

    public function test_adjustment_requires_independent_approval(): void
    {
        $f = $this->stocked(5);
        $this->as('inventory');
        $d = $this->postJson('/api/v1/documents', ['type' => 'adjustment', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'meta' => ['direction' => 'subtract', 'reason' => 'Damage correction'], 'lines' => [['product_id' => $f['product']->id, 'quantity' => 1, 'batch' => 'TEST', 'location_id' => $f['location']->id]]])->assertSuccessful()->json('data');
        $this->action($d, 'submit')->assertJsonPath('data.status', 'pending_approval');
        $this->action($d, 'approve')->assertForbidden();
        $manager = $this->as('manager');
        $this->action($d, 'approve')->assertJsonPath('data.approved_by', $manager->id);
        $this->assertEquals(4, Balance::where('product_id', $f['product']->id)->sum('quantity'));
    }

    public function test_creator_cannot_approve_even_with_approval_permission(): void
    {
        $f = $this->stocked(5);
        $this->as('manager');
        $d = $this->postJson('/api/v1/documents', ['type' => 'adjustment', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'lines' => [['product_id' => $f['product']->id, 'quantity' => 1, 'batch' => 'TEST', 'location_id' => $f['location']->id]]])->assertSuccessful()->json('data');
        $this->action($d, 'submit')->assertSuccessful();
        $this->action($d, 'approve')->assertUnprocessable();
    }

    public function test_count_cutoff_preserves_movements_after_count(): void
    {
        $f = $this->stocked(5);
        $this->as('inventory');
        $d = $this->postJson('/api/v1/documents', ['type' => 'count', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'lines' => [['product_id' => $f['product']->id, 'quantity' => 1, 'batch' => 'TEST', 'location_id' => $f['location']->id]]])->assertSuccessful()->json('data');
        $this->action($d, 'count', ['line_id' => $d['lines'][0]['id'], 'counted' => 4])->assertSuccessful();
        $this->getJson('/api/v1/documents/'.$d['id'])->assertJsonMissingPath('data.lines.0.snapshot');
        $this->action($d, 'submit')->assertSuccessful();
        $this->as('admin');
        $extra = $this->postJson('/api/v1/documents', ['type' => 'inbound', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'lines' => [['product_id' => $f['product']->id, 'quantity' => 2, 'batch' => 'TEST']]])->assertSuccessful()->json('data');
        $this->action($extra, 'receive', ['line_id' => $extra['lines'][0]['id'], 'quantity' => 2, 'scan' => $f['product']->barcode])->assertSuccessful();
        $this->action($extra, 'putaway', ['line_id' => $extra['lines'][0]['id'], 'quantity' => 2, 'location_id' => $f['location']->id])->assertSuccessful();
        $this->as('manager');
        $this->action($d, 'approve')->assertSuccessful();
        $this->assertEquals(6, Balance::where('product_id', $f['product']->id)->sum('quantity'));
    }

    public function test_dashboard_reports_and_exports_are_real(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('kpi.physical', (int) Balance::sum('quantity'));
        $this->getJson('/api/v1/reports')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk();
        $this->get('/api/v1/exports/inventory?format=xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_transfer_has_transit_then_arrival(): void
    {
        $f = $this->stocked(4);
        $destination = Warehouse::where('id', '!=', $f['warehouse']->id)->first();
        $t = $this->postJson('/api/v1/documents', ['type' => 'transfer', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'destination_warehouse_id' => $destination->id, 'lines' => [['product_id' => $f['product']->id, 'quantity' => 2, 'batch' => 'TEST', 'location_id' => $f['location']->id]]])->assertSuccessful()->json('data');
        $this->action($t, 'send')->assertJsonPath('data.status', 'in_transit');
        $this->assertEquals(2, Balance::where('product_id', $f['product']->id)->where('condition', 'transit')->sum('quantity'));
        $this->action($t, 'arrive', ['location_id' => Location::where('warehouse_id', $destination->id)->where('type', 'storage')->value('id')])->assertJsonPath('data.status', 'completed');
        $this->assertEquals(4, Balance::where('product_id', $f['product']->id)->sum('quantity'));
        $this->assertEquals(2, Balance::where('product_id', $f['product']->id)->where('warehouse_id', $destination->id)->sum('quantity'));
    }

    public function test_payment_is_idempotent_and_billing_is_not_duplicated(): void
    {
        $i = Invoice::first();
        $data = ['amount' => $i->total, 'reference' => 'TEST-PAYMENT', 'paid_at' => today('Asia/Jakarta')->toDateString(), 'idempotency_key' => 'payment-repeat'];
        $this->postJson('/api/v1/invoices/'.$i->id.'/payments', $data)->assertJsonPath('data.status', 'paid');
        $this->postJson('/api/v1/invoices/'.$i->id.'/payments', $data)->assertSuccessful();
        $this->assertEquals(1, $i->payments()->count());
        $this->postJson('/api/v1/invoices', ['client_id' => $i->client_id, 'period_start' => $i->period_start, 'period_end' => $i->period_end, 'due_date' => $i->due_date, 'idempotency_key' => 'duplicate-invoice'])->assertUnprocessable();
    }

    public function test_forged_line_counters_and_cross_client_products_are_rejected(): void
    {
        $f = $this->fixture();
        $payload = ['type' => 'order', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'lines' => [['product_id' => $f['product']->id, 'quantity' => 1, 'picked' => 100]]];
        $this->postJson('/api/v1/documents', $payload)->assertUnprocessable();
        $payload['lines'][0] = ['product_id' => Product::where('client_id', '!=', $f['client']->id)->value('id'), 'quantity' => 1];
        $this->postJson('/api/v1/documents', $payload)->assertUnprocessable();
    }

    public function test_available_and_low_stock_exclude_receiving_and_expired_goods(): void
    {
        $f = $this->fixture(5);
        $line = $f['in']['lines'][0];
        $this->action($f['in'], 'receive', ['line_id' => $line['id'], 'quantity' => 5, 'scan' => $f['product']->barcode])->assertSuccessful();
        $this->assertEquals(0, Balance::where('product_id', $f['product']->id)->first()->available);
        $low = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('low_stock'));
        $this->assertEquals(0, $low->firstWhere('sku', $f['product']->sku)['available']);
        $this->action($f['in'], 'putaway', ['line_id' => $line['id'], 'quantity' => 5, 'location_id' => $f['location']->id])->assertSuccessful();
        $b = Balance::where('product_id', $f['product']->id)->where('location_id', $f['location']->id)->first();
        $this->assertEquals(5, $b->available);
        $b->update(['expiry' => now('Asia/Jakarta')->subDay()->toDateString()]);
        $this->assertEquals(0, $b->fresh()->available);
        $o = $this->order($f);
        $this->action($o, 'reserve')->assertJsonPath('data.status', 'backorder');
    }

    public function test_full_count_is_blind_in_create_list_and_action(): void
    {
        $f = $this->stocked(5);
        $this->as('inventory');
        $d = $this->postJson('/api/v1/documents', ['type' => 'count', 'client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'meta' => ['count_type' => 'full'], 'lines' => [['product_id' => $f['product']->id, 'quantity' => 1, 'batch' => 'TEST', 'location_id' => $f['location']->id]]])->assertSuccessful()->assertJsonMissingPath('data.lines.0.snapshot')->json('data');
        $this->assertGreaterThan(1, count($d['lines']));
        foreach ($d['lines'] as $l) {
            $this->assertEquals(1, $l['quantity']);
        }$this->getJson('/api/v1/documents?type=count')->assertOk()->assertJsonMissingPath('data.0.lines.0.snapshot');
        $this->action($d, 'count', ['line_id' => $d['lines'][0]['id'], 'counted' => 1])->assertSuccessful()->assertJsonMissingPath('data.lines.0.snapshot');
    }

    public function test_queued_export_owner_and_scope_are_checked_at_download(): void
    {
        Storage::fake('local');
        $u = $this->as('client');
        $job = $this->postJson('/api/v1/report-jobs', ['kind' => 'inventory'])->assertAccepted()->json('data');
        (new ExportReport($job['id']))->handle();
        $this->get('/api/v1/report-jobs/'.$job['id'].'/download')->assertOk();
        $u->clients()->sync([]);
        $u->unsetRelation('clients');
        $this->get('/api/v1/report-jobs/'.$job['id'].'/download')->assertForbidden();
        $this->as('sora');
        $this->get('/api/v1/report-jobs/'.$job['id'].'/download')->assertNotFound();
    }

    public function test_split_dispatch_preserves_remaining_reservations(): void
    {
        $f = $this->stocked(5);
        $o = $this->order($f, 4);
        $l = $o['lines'][0];
        $this->action($o, 'reserve')->assertSuccessful();
        $this->action($o, 'pick', ['line_id' => $l['id'], 'quantity' => 2, 'scan' => $f['product']->barcode, 'location_code' => $f['location']->code])->assertSuccessful();
        $this->action($o, 'pack', ['line_id' => $l['id'], 'quantity' => 2, 'scan' => $f['product']->barcode, 'packaging_id' => Catalog::where('kind', 'packaging')->value('id'), 'actual_weight' => '1.0'])->assertSuccessful();
        $this->action($o, 'manifest', ['courier_id' => Catalog::where('kind', 'courier')->value('id'), 'tracking_number' => 'SPLIT-01', 'manifest_number' => 'MNF-SPLIT'])->assertSuccessful();
        $this->action($o, 'dispatch', ['scan' => 'SPLIT-01'])->assertJsonPath('data.status', 'partially_dispatched');
        $this->assertEquals(3, Balance::where('product_id', $f['product']->id)->sum('quantity'));
        $this->assertEquals(2, Balance::where('product_id', $f['product']->id)->sum('reserved'));
    }

    public function test_storage_accrual_is_idempotent_and_keeps_rate_snapshot(): void
    {
        $date = now('Asia/Jakarta')->subDay()->toDateString();
        (new AccrueStorage($date))->handle();
        $first = Charge::where('service', 'storage')->get();
        $this->assertNotEmpty($first);
        Tariff::where('service', 'storage')->update(['rate' => 999]);
        (new AccrueStorage($date))->handle();
        $second = Charge::where('service', 'storage')->get();
        $this->assertEquals($first->count(), $second->count());
        $this->assertEquals($first->sum('amount'), $second->sum('amount'));
    }

    public function test_master_permissions_distinguish_client_products_and_shared_locations(): void
    {
        $this->as('client');
        $location = Location::first();
        $this->putJson('/api/v1/master/locations/'.$location->id, ['warehouse_id' => $location->warehouse_id, 'code' => $location->code, 'zone' => 'Malicious', 'type' => $location->type, 'capacity' => 100])->assertForbidden();
        $this->postJson('/api/v1/master/clients', ['code' => 'BAD', 'name' => 'Unauthorized'])->assertForbidden();
        $this->as('inventory');
        $this->putJson('/api/v1/master/locations/'.$location->id, ['warehouse_id' => $location->warehouse_id, 'code' => $location->code, 'zone' => 'Approved zone', 'type' => $location->type, 'capacity' => 5000])->assertSuccessful();
        $this->as('operations');
        $this->postJson('/api/v1/master/clients', ['code' => 'NEW-CLIENT', 'name' => 'New operational client'])->assertSuccessful();
        $this->getJson('/api/v1/master/clients')->assertJsonFragment(['code' => 'NEW-CLIENT']);
    }

    public function test_inbound_discrepancy_is_approved_by_a_separate_actor(): void
    {
        $f = $this->fixture(5);
        $line = $f['in']['lines'][0];
        $this->action($f['in'], 'receive', ['line_id' => $line['id'], 'quantity' => 3, 'scan' => $f['product']->barcode])->assertSuccessful();
        $this->action($f['in'], 'putaway', ['line_id' => $line['id'], 'quantity' => 3, 'location_id' => $f['location']->id])->assertSuccessful();
        $this->action($f['in'],'close-receiving',['reason' => 'Supplier shortage'])->assertJsonPath('data.status','pending_inbound_approval');
        $this->action($f['in'],'approve-receiving')->assertUnprocessable();
        $this->as('manager');
        $this->action($f['in'],'approve-receiving')->assertJsonPath('data.status','closed_difference');
        $this->assertEquals(3,Balance::where('product_id',$f['product']->id)->sum('quantity'));
    }

    public function test_demo_login_is_disabled_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
        $this->postJson('/api/v1/login',['email' => 'admin@aldef.local', 'password' => 'AldefLocal!2026'])->assertUnprocessable();
    }

    public function test_local_marketplace_adapter_creates_only_scoped_simulated_order(): void
    {
        $f = $this->stocked();
        $this->as('client');
        $this->postJson('/api/v1/integrations/marketplace',['client_id' => $f['client']->id, 'warehouse_id' => $f['warehouse']->id, 'product_id' => $f['product']->id, 'quantity' => 1, 'idempotency_key' => 'simulator-test'])->assertSuccessful()->assertJsonPath('simulated',true)->assertJsonPath('data.meta.source','SIMULATOR LOKAL MARKETPLACE');
    }
}
