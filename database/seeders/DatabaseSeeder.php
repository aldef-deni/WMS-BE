<?php

namespace Database\Seeders;

use App\Models\Catalog;
use App\Models\Charge;
use App\Models\Client;
use App\Models\LedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BillingService;
use App\Services\WorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeder hanya boleh berjalan pada local/testing.');
        }
        if (Client::exists()) {
            return;
        }
        DB::transaction(function () {
            foreach (config('permissions.roles') as $name => $permissions) {
                Role::create(compact('name', 'permissions'));
            }
            $admin = User::create(['name' => 'Ade Zulham', 'email' => 'admin@aldef.local', 'password' => Hash::make('AldefLocal!2026')]);
            $admin->roles()->attach(Role::where('name', 'Super Admin')->first());
            Auth::login($admin);
            $clients = collect([['code' => 'VRD', 'name' => 'Verdana Nutrition', 'email' => 'ops@verdana.example', 'address' => 'Jakarta Selatan'], ['code' => 'SORA', 'name' => 'Sora Living', 'email' => 'ops@sora.example', 'address' => 'Tangerang']])->map(fn ($c) => Client::create($c));
            $warehouses = collect([['code' => 'JKT-01', 'name' => 'Jakarta Fulfillment Hub', 'address' => 'Cakung, Jakarta Timur', 'capacity' => 20000], ['code' => 'BKS-01', 'name' => 'Bekasi Distribution Hub', 'address' => 'Cikarang, Bekasi', 'capacity' => 12000]])->map(fn ($w) => Warehouse::create($w));
            foreach ($warehouses as $w) {
                foreach ([['RCV-01', 'receiving', 'Inbound'], ['STG-01', 'staging', 'Outbound'], ['QC-01', 'quarantine', 'QC'], ['DMG-01', 'damaged', 'QC'], ['TRN-01', 'transit', 'Transfer'], ['A-01-01', 'storage', 'A'], ['A-01-02', 'storage', 'A'], ['B-02-01', 'storage', 'B']] as [$code,$type,$zone]) {
                    Location::create(['warehouse_id' => $w->id, 'code' => $code, 'type' => $type, 'zone' => $zone, 'capacity' => 5000]);
                }
            }
            foreach (['company' => ['name' => 'ALDEF Fulfillment Center', 'timezone' => 'Asia/Jakarta', 'logo' => '/aldef-logo-2026.png'], 'sla' => ['dispatch_hours' => 24], 'numbering' => ['prefix' => 'ALDEF'], 'notifications' => ['low_stock' => true, 'expiry_days' => 30]] as $key => $value) {
                Setting::create(compact('key', 'value'));
            }
            $box = Catalog::create(['kind' => 'packaging', 'code' => 'BOX-M', 'name' => 'Box medium · 25 × 20 × 15 cm', 'meta' => ['weight' => 0.15]]);
            Catalog::create(['kind' => 'packaging', 'code' => 'POLY-S', 'name' => 'Polymailer S']);
            $courier = Catalog::create(['kind' => 'courier', 'code' => 'LOCAL', 'name' => 'Kurir Manual / Simulator Lokal']);
            Catalog::create(['kind' => 'unit', 'code' => 'pcs', 'name' => 'Pieces']);
            foreach ($clients as $client) {
                foreach (['receiving' => 750, 'storage' => 35, 'picking' => 1000, 'packing' => 1500, 'packaging' => 2500, 'shipping' => 18000, 'return' => 2000] as $service => $rate) {
                    Tariff::create(['client_id' => $client->id, 'service' => $service, 'unit' => match ($service) {
                        'storage' => 'unit_day','shipping','packaging' => 'package',default => 'unit'
                    }, 'rate' => $rate]);
                }
            }
            $names = [['Daily Protein Vanilla', 'VRD-PRO-VAN', 'Vanilla / 500 g', 0.55], ['Daily Protein Chocolate', 'VRD-PRO-CHO', 'Chocolate / 500 g', 0.55], ['Hydration Electrolyte', 'VRD-HYD-30', '30 sachet', 0.35], ['Ceramic Mug Sand', 'SORA-MUG-SND', 'Sand / 350 ml', 0.45], ['Linen Storage Basket', 'SORA-BSK-L', 'Natural / L', 0.8], ['Aroma Diffuser Cedar', 'SORA-DIF-CDR', 'Cedar / 100 ml', 0.3]];
            $products = collect();
            foreach ($names as $idx => [$name,$sku,$variant,$weight]) {
                $products->push(Product::create(['client_id' => $clients[$idx < 3 ? 0 : 1]->id, 'name' => $name, 'sku' => $sku, 'barcode' => '89920260000'.($idx + 1), 'variant' => $variant, 'unit' => 'pcs', 'weight' => $weight, 'length' => 20, 'width' => 15, 'height' => 10, 'minimum_stock' => 25]));
            }
            $w = $warehouses[0];
            $workflow = app(WorkflowService::class);
            $storage = Location::where('warehouse_id', $w->id)->where('code', 'A-01-01')->first();
            foreach ($products as $idx => $p) {
                $in = $workflow->create(['type' => 'inbound', 'client_id' => $p->client_id, 'warehouse_id' => $w->id, 'scheduled_at' => now(), 'lines' => [['product_id' => $p->id, 'quantity' => [420, 320, 220, 160, 80, 20][$idx], 'batch' => 'LOT-26-10', 'expiry' => now()->addMonths(6)->toDateString()]]]);
                $line = $in->lines[0];
                $q = $line->quantity;
                $workflow->act($in, 'receive', ['line_id' => $line->id, 'quantity' => $q, 'scan' => $p->barcode, 'condition' => 'sellable'], 'seed-receive-'.$idx);
                $workflow->act($in, 'putaway', ['line_id' => $line->id, 'quantity' => $q, 'location_id' => $storage->id], 'seed-putaway-'.$idx);
                $day = now()->subDays(6 - $idx)->startOfDay()->addHours(3);
                $in->update(['created_at' => $day]);
                LedgerEntry::where('document_id', $in->id)->update(['created_at' => $day]);
                Charge::where('document_id', $in->id)->update(['created_at' => $day]);
            }
            foreach (range(1, 12) as $i) {
                $p = $products[($i - 1) % 6];
                $order = $workflow->create(['type' => 'order', 'client_id' => $p->client_id, 'warehouse_id' => $w->id, 'priority' => $i % 4 === 0 ? 'urgent' : 'normal', 'meta' => ['recipient' => ['Nadia Putri', 'Rizky Pratama', 'Dewi Lestari', 'Bima Saputra'][$i % 4], 'address' => 'Jl. Merdeka No. '.$i.', Jakarta'], 'lines' => [['product_id' => $p->id, 'quantity' => ($i % 3) + 1, 'batch' => 'LOT-26-10']]]);
                $line = $order->lines[0];
                $quantity = $line->quantity;
                if ($i <= 10) {
                    $workflow->act($order, 'reserve', [], 'seed-reserve-'.$i);
                }
                if ($i <= 8) {
                    $workflow->act($order, 'pick', ['line_id' => $line->id, 'quantity' => $quantity, 'scan' => $p->barcode, 'location_code' => $storage->code], 'seed-pick-'.$i);
                }
                if ($i <= 6) {
                    $workflow->act($order, 'pack', ['line_id' => $line->id, 'quantity' => $quantity, 'scan' => $p->barcode, 'packaging_id' => $box->id, 'actual_weight' => $p->weight * $quantity], 'seed-pack-'.$i);
                }
                if ($i <= 4) {
                    $workflow->act($order, 'manifest', ['courier_id' => $courier->id, 'tracking_number' => 'ALDEF-DEMO-'.$i, 'manifest_number' => 'MNF-DEMO-01'], 'seed-manifest-'.$i);
                }
                if ($i <= 3) {
                    $workflow->act($order, 'dispatch', ['scan' => 'ALDEF-DEMO-'.$i], 'seed-dispatch-'.$i);
                    $date = now()->subDays(4 - $i);
                    $order->update(['created_at' => $date, 'dispatched_at' => $date]);
                    LedgerEntry::where('document_id', $order->id)->update(['created_at' => $date]);
                    Charge::where('document_id', $order->id)->update(['created_at' => $date]);
                }
            }
            foreach (['manager' => 'Warehouse Manager', 'inbound' => 'Inbound Staff', 'inventory' => 'Inventory Controller', 'picker' => 'Picker', 'packer' => 'Packer', 'shipping' => 'Shipping Staff', 'qc' => 'Return & QC Staff', 'finance' => 'Finance', 'cs' => 'Customer Service', 'auditor' => 'Auditor', 'operations' => 'Operations Admin', 'client' => 'Client Admin', 'viewer' => 'Client Viewer', 'sora' => 'Client Admin'] as $login => $role) {
                $u = User::create(['name' => $role.($login === 'sora' ? ' Sora' : ''), 'email' => $login.'@aldef.local', 'password' => Hash::make('AldefLocal!2026')]);
                $u->roles()->attach(Role::where('name', $role)->first());
                $u->warehouses()->attach($warehouses->pluck('id'));
                $u->clients()->attach(in_array($login, ['client', 'viewer']) ? [$clients[0]->id] : ($login === 'sora' ? [$clients[1]->id] : $clients->pluck('id')));
            }
            app(BillingService::class)->invoice(['client_id' => $clients[0]->id, 'period_start' => now('Asia/Jakarta')->subDays(7)->toDateString(), 'period_end' => now('Asia/Jakarta')->toDateString(), 'due_date' => now('Asia/Jakarta')->addDays(14)->toDateString()]);
        });
    }
}
