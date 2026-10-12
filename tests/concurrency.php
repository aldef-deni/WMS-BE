<?php

// Two real PHP processes contend on one physical unit in the dedicated MySQL test schema.
use App\Models\Balance;
use App\Models\Client;
use App\Models\Document;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'aldef_fulfillment_test') {
    throw new RuntimeException('Concurrency test schema mismatch.');
}
if (! User::exists()) {
    (new DatabaseSeeder)->run();
}
Auth::login(User::where('email', 'admin@aldef.local')->firstOrFail());
if (($argv[1] ?? '') === 'worker') {
    $id = (int) $argv[2];
    $result = DB::transaction(function () use ($id) {
        $doc = Document::findOrFail($id);
        $result = app(WorkflowService::class)->act($doc, 'reserve', [], 'race-'.$id);
        usleep(350000);

        return ['id' => $id, 'status' => $result->status];
    }, 5);
    echo json_encode($result);
    exit;
}
$tag = bin2hex(random_bytes(6));
$w = Warehouse::first();
$location = Location::where('warehouse_id', $w->id)->where('type', 'storage')->first();
$fixture = DB::transaction(function () use ($tag, $w, $location) {
    $c = Client::create(['code' => 'RACE-'.$tag, 'name' => 'Concurrency fixture '.$tag]);
    foreach (['receiving', 'picking', 'packing', 'packaging', 'shipping', 'return', 'storage'] as $s) {
        Tariff::create(['client_id' => $c->id, 'service' => $s, 'unit' => $s === 'storage' ? 'unit_day' : (in_array($s, ['shipping', 'packaging']) ? 'package' : 'unit'), 'rate' => '1.00']);
    }$p = Product::create(['client_id' => $c->id, 'sku' => 'LAST-'.$tag, 'barcode' => 'LAST-'.$tag, 'name' => 'Last physical unit']);
    $flow = app(WorkflowService::class);
    $in = $flow->create(['type' => 'inbound', 'client_id' => $c->id, 'warehouse_id' => $w->id, 'lines' => [['product_id' => $p->id, 'quantity' => 1]]]);
    $line = $in->lines[0];
    $flow->act($in, 'receive', ['line_id' => $line->id, 'quantity' => 1, 'scan' => $p->barcode], 'race-receive-'.$tag);
    $flow->act($in, 'putaway', ['line_id' => $line->id, 'quantity' => 1, 'location_id' => $location->id], 'race-putaway-'.$tag);
    $orders = [];
    for ($i = 0; $i < 2; $i++) {
        $orders[] = $flow->create(['type' => 'order', 'client_id' => $c->id, 'warehouse_id' => $w->id, 'lines' => [['product_id' => $p->id, 'quantity' => 1]]])->id;
    }

return ['product' => $p->id, 'orders' => $orders];
});
$processes = [];
foreach ($fixture['orders'] as $id) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    fclose($pipes[0]);
    $processes[] = [$proc, $pipes];
}
$results = [];
foreach ($processes as [$proc,$pipes]) {
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException($error.$output);
    }$results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
$statuses = array_column($results, 'status');
sort($statuses);
$reserved = Balance::where('product_id', $fixture['product'])->sum('reserved');
$physical = Balance::where('product_id', $fixture['product'])->sum('quantity');
if ($statuses !== ['allocated', 'backorder'] || (int) $reserved !== 1 || (int) $physical !== 1) {
    throw new RuntimeException('Concurrent allocation invariant failed: '.json_encode($results));
}
echo json_encode(['result' => 'PASS', 'processes' => 2, 'statuses' => $statuses, 'physical' => (int) $physical, 'reserved' => (int) $reserved, 'database' => DB::connection()->getDatabaseName()]).PHP_EOL;
