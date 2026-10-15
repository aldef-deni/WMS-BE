<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DemoReset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoResetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('aldef_fulfillment_test', DB::connection()->getDatabaseName(), 'Tests must use the dedicated test schema.');
        $this->seed(DatabaseSeeder::class);
        $demo = User::firstOrCreate(['email' => DemoReset::EMAIL], ['name' => 'Demo Super Admin', 'password' => DemoReset::PASSWORD]);
        $demo->roles()->syncWithoutDetaching([Role::where('name', 'Super Admin')->value('id')]);
        app(DemoReset::class)->capture();
    }

    public function test_demo_login_starts_timer_and_reset_restores_baseline(): void
    {
        $documents = Document::count();
        $clients = Client::count();
        $this->withHeader('Referer', 'http://127.0.0.1:5173/')->postJson('/api/v1/login', ['email' => DemoReset::EMAIL, 'password' => DemoReset::PASSWORD])->assertOk()->assertJsonPath('super_admin', true);
        $due = app(DemoReset::class)->state()->reset_due_at;
        $this->assertNotNull($due);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, strtotime($due), 60);

        $client = Client::first();
        $product = Product::where('client_id', $client->id)->first();
        $this->postJson('/api/v1/documents', ['type' => 'order', 'client_id' => $client->id, 'warehouse_id' => Warehouse::first()->id, 'meta' => ['recipient' => 'Demo', 'address' => 'Jakarta'], 'lines' => [['product_id' => $product->id, 'quantity' => 1]]])->assertSuccessful();
        Client::create(['code' => 'DEMO-NEW', 'name' => 'Klien baru demo']);
        User::where('email', DemoReset::EMAIL)->first()->update(['password' => 'changed']);
        $this->assertSame($documents + 1, Document::count());

        $this->assertFalse(app(DemoReset::class)->resetIfDue(), 'Reset must wait for the 24-hour timer.');
        $this->travel(25)->hours();
        $this->assertTrue(app(DemoReset::class)->resetIfDue());

        $this->assertSame($documents, Document::count());
        $this->assertSame($clients, Client::count());
        $this->assertNull(app(DemoReset::class)->state()->reset_due_at);
        $this->assertTrue(auth()->guard('web')->validate(['email' => DemoReset::EMAIL, 'password' => DemoReset::PASSWORD]));
    }

    public function test_overdue_demo_login_resets_before_starting_new_timer(): void
    {
        $documents = Document::count();
        DB::table('demo_baselines')->update(['reset_due_at' => now()->subMinute()]);
        Client::create(['code' => 'DEMO-OLD', 'name' => 'Sisa sesi demo']);
        $this->withHeader('Referer', 'http://127.0.0.1:5173/')->postJson('/api/v1/login', ['email' => DemoReset::EMAIL, 'password' => DemoReset::PASSWORD])->assertOk();
        $this->assertFalse(Client::where('code', 'DEMO-OLD')->exists());
        $this->assertSame($documents, Document::count());
        $this->assertTrue(now()->lt(app(DemoReset::class)->state()->reset_due_at));
    }
}
