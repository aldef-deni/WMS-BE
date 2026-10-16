<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\Client;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TraceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function action(array $doc, string $action, array $data = [])
    {
        return $this->postJson('/api/v1/documents/'.$doc['id'].'/actions/'.$action, $data + ['idempotency_key' => (string) Str::uuid()])->assertSuccessful()->json('data');
    }

    /** Receive and put away an item, then ship part of it to a customer. */
    private function shippedBatch(): array
    {
        $client = Client::where('code', 'VRD')->first();
        $warehouse = Warehouse::first();
        $sku = 'TRC-'.strtoupper(bin2hex(random_bytes(4)));
        $batch = 'LOT-'.$sku;
        $product = Product::create(['client_id' => $client->id, 'sku' => $sku, 'barcode' => 'BC'.$sku, 'name' => 'Trace item', 'unit' => 'pcs', 'weight' => '0.200', 'minimum_stock' => 1]);
        $location = Location::where('warehouse_id', $warehouse->id)->where('type', 'storage')->first();
        $in = $this->postJson('/api/v1/documents', ['type' => 'inbound', 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'lines' => [['product_id' => $product->id, 'quantity' => 3, 'batch' => $batch]]])->assertSuccessful()->json('data');
        $line = $in['lines'][0];
        $this->action($in, 'receive', ['line_id' => $line['id'], 'quantity' => 3, 'scan' => $product->barcode]);
        $this->action($in, 'putaway', ['line_id' => $line['id'], 'quantity' => 3, 'location_id' => $location->id]);
        $order = $this->postJson('/api/v1/documents', ['type' => 'order', 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'meta' => ['recipient' => 'Sinta Maharani', 'address' => 'Jl. Kenanga 5, Bandung'], 'lines' => [['product_id' => $product->id, 'quantity' => 2, 'batch' => $batch]]])->assertSuccessful()->json('data');
        $l = $order['lines'][0];
        $this->action($order, 'reserve');
        $this->action($order, 'pick', ['line_id' => $l['id'], 'quantity' => 2, 'scan' => $product->barcode, 'location_code' => $location->code]);
        $this->action($order, 'pack', ['line_id' => $l['id'], 'quantity' => 2, 'scan' => $product->barcode, 'packaging_id' => Catalog::where('kind', 'packaging')->value('id'), 'actual_weight' => '0.5']);
        $resi = 'JNT'.random_int(100000000, 999999999);
        $this->action($order, 'manifest', ['courier_id' => Catalog::where('kind', 'courier')->value('id'), 'tracking_number' => $resi, 'manifest_number' => 'MNF-'.$sku]);
        $this->action($order, 'dispatch', ['scan' => $resi]);

        return compact('product', 'batch', 'in', 'order', 'resi', 'location');
    }

    public function test_batch_is_traced_from_receiving_to_customer_shipment(): void
    {
        $f = $this->shippedBatch();
        $data = $this->getJson('/api/v1/trace?q='.$f['batch'])->assertOk()->json('data');

        $this->assertSame(['batch'], $data['matched_by']);
        $this->assertSame(['received' => 3, 'on_hand' => 1, 'reserved' => 0, 'dispatched' => 2, 'returned' => 0, 'locations' => 1], $data['summary']);
        $events = array_column($data['timeline'], 'event');
        foreach (['receiving', 'putaway_in', 'reserve', 'pick_in', 'dispatch'] as $event) {
            $this->assertContains($event, $events);
        }
        $this->assertSame('dispatch', $data['timeline'][0]['event'], 'Timeline is newest first.');
        $this->assertSame('Ade Zulham', $data['timeline'][0]['user']);
        $this->assertStringEndsWith('+00:00', $data['timeline'][0]['created_at']);
        $order = collect($data['documents'])->firstWhere('id', $f['order']['id']);
        $this->assertSame(['Sinta Maharani', $f['resi'], 2, 2], [$order['recipient'], $order['tracking_number'], $order['quantity'], $order['dispatched']]);
        $this->assertNotNull(collect($data['documents'])->firstWhere('id', $f['in']['id']));
        $this->assertSame(1, collect($data['positions'])->firstWhere('location', $f['location']->code)['quantity']);

        $other = Warehouse::where('id', '!=', $f['location']->warehouse_id)->value('id');
        $filtered = $this->getJson('/api/v1/trace?q='.$f['batch'].'&warehouse_id='.$other)->assertOk()->json('data');
        $this->assertSame([[], [], []], [$filtered['positions'], $filtered['timeline'], $filtered['documents']]);
    }

    public function test_sku_and_serial_lookup(): void
    {
        $f = $this->shippedBatch();
        $data = $this->getJson('/api/v1/trace?q='.$f['product']->sku)->assertOk()->json('data');
        $this->assertSame(['product'], $data['matched_by']);
        $this->assertSame($f['product']->sku, $data['products'][0]['sku']);
        $this->assertSame(2, $data['summary']['dispatched']);

        $client = Client::where('code', 'VRD')->first();
        $warehouse = Warehouse::first();
        $serialProduct = Product::create(['client_id' => $client->id, 'sku' => 'SN-'.$f['product']->sku, 'barcode' => 'SNB'.$f['product']->sku, 'name' => 'Serial item', 'unit' => 'pcs', 'weight' => '1', 'minimum_stock' => 1, 'serial_tracked' => true]);
        $serial = 'SN'.random_int(10000000, 99999999);
        $in = $this->postJson('/api/v1/documents', ['type' => 'inbound', 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'lines' => [['product_id' => $serialProduct->id, 'quantity' => 1, 'serial' => $serial]]])->assertSuccessful()->json('data');
        $line = $in['lines'][0];
        $this->action($in, 'receive', ['line_id' => $line['id'], 'quantity' => 1, 'scan' => $serialProduct->barcode]);
        $data = $this->getJson('/api/v1/trace?q='.strtolower($serial))->assertOk()->json('data');
        $this->assertSame(['serial'], $data['matched_by']);
        $this->assertSame([1, $serial], [$data['summary']['on_hand'], $data['positions'][0]['serial']]);
    }

    public function test_trace_respects_client_scope_and_validation(): void
    {
        $f = $this->shippedBatch();
        $this->getJson('/api/v1/trace?q=x')->assertUnprocessable();
        $this->as('sora');
        $data = $this->getJson('/api/v1/trace?q='.$f['batch'])->assertOk()->json('data');
        $this->assertSame([], $data['positions']);
        $this->assertSame([], $data['documents']);
        $this->as('packer');
        $this->getJson('/api/v1/trace?q='.$f['batch'])->assertForbidden();
    }

    public function test_orders_can_be_searched_by_tracking_number_recipient_and_sku(): void
    {
        $f = $this->shippedBatch();
        foreach ([$f['resi'], strtolower(substr($f['resi'], 0, 7)), 'sinta maha', 'SINTA', strtolower($f['product']->sku)] as $term) {
            $ids = array_column($this->getJson('/api/v1/documents?type=order&search='.urlencode($term))->assertOk()->json('data'), 'id');
            $this->assertContains($f['order']['id'], $ids, 'Search term: '.$term);
        }
        $this->assertSame([], $this->getJson('/api/v1/documents?type=order&search=%25%25tidak-ada%25')->assertOk()->json('data'));
    }
}
