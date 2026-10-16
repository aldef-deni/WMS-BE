<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DemoReset;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('aldef_fulfillment_test', DB::connection()->getDatabaseName(), 'Tests must use the dedicated test schema.');
        $this->seed(DatabaseSeeder::class);
    }

    private function picker(): User
    {
        $u = User::where('email', 'picker@aldef.local')->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    private function demo(): User
    {
        $u = User::firstOrCreate(['email' => DemoReset::EMAIL], ['name' => 'Demo Super Admin', 'password' => DemoReset::PASSWORD]);
        $u->roles()->syncWithoutDetaching([Role::where('name', 'Super Admin')->value('id')]);
        $this->actingAs($u);

        return $u;
    }

    public function test_any_role_can_view_and_update_own_profile(): void
    {
        $u = $this->picker();
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.user.email', 'picker@aldef.local')->assertJsonPath('data.demo', false);
        $this->putJson('/api/v1/profile', ['name' => 'Picker Satu', 'email' => 'picker@aldef.local', 'phone' => '+62 812-3456-7890', 'job_title' => 'Picker Shift Pagi'])
            ->assertOk()->assertJsonPath('user.name', 'Picker Satu')->assertJsonPath('user.phone', '+62 812-3456-7890');
        $this->putJson('/api/v1/profile', ['name' => 'X', 'email' => 'admin@aldef.local'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/v1/profile', ['name' => 'X', 'email' => 'picker@aldef.local', 'phone' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame('Picker Shift Pagi', $u->fresh()->job_title);
        $this->getJson('/api/v1/profile/activity')->assertOk()->assertJsonPath('data.0.action', 'profile.update');
    }

    public function test_password_change_requires_current_password(): void
    {
        $u = $this->picker();
        $this->putJson('/api/v1/profile/password', ['current_password' => 'wrong', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/profile/password', ['current_password' => 'AldefLocal!2026', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/profile/password', ['current_password' => 'AldefLocal!2026', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])->assertNoContent();
        $this->assertTrue(Hash::check('NewPassword!2026', $u->fresh()->password));
    }

    public function test_avatar_upload_is_cropped_served_and_removed(): void
    {
        Storage::fake('local');
        $u = $this->picker();
        $res = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 800, 600)], ['Accept' => 'application/json'])->assertOk();
        $url = $res->json('user.avatar_url');
        $this->assertStringStartsWith('/avatars/'.$u->id.'?v=', $url);
        $path = $u->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([320, 320], [$w, $h]);
        $this->get('/api/v1'.$url)->assertOk();
        $this->assertArrayNotHasKey('avatar_path', $res->json('user'));

        $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson('/api/v1/profile/avatar')->assertOk()->assertJsonPath('user.avatar_url', null);
        Storage::disk('local')->assertMissing($path);
        $this->get('/api/v1/avatars/'.$u->id)->assertNotFound();
    }

    public function test_demo_account_cannot_change_email_password_or_end_sessions(): void
    {
        $this->demo();
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.demo', true);
        $this->putJson('/api/v1/profile', ['name' => 'Demo Baru', 'email' => 'lain@aldeftech.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/v1/profile', ['name' => 'Demo Baru', 'email' => DemoReset::EMAIL])->assertOk()->assertJsonPath('user.name', 'Demo Baru');
        $this->putJson('/api/v1/profile/password', ['current_password' => 'demo', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->deleteJson('/api/v1/profile/sessions', ['current_password' => 'demo'])->assertUnprocessable()->assertJsonValidationErrors('account');
    }

    public function test_demo_reset_keeps_real_account_profile(): void
    {
        $this->demo();
        app(DemoReset::class)->capture();
        $picker = User::where('email', 'picker@aldef.local')->first();
        $picker->forceFill(['name' => 'Picker Diubah', 'phone' => '0811', 'avatar_path' => 'avatars/x.jpg'])->save();
        $demo = User::where('email', DemoReset::EMAIL)->first();
        $demo->forceFill(['name' => 'Demo Diubah', 'phone' => '0822'])->save();
        app(DemoReset::class)->restore();
        $this->assertSame(['Picker Diubah', '0811', 'avatars/x.jpg'], [$picker->fresh()->name, $picker->fresh()->phone, $picker->fresh()->avatar_path]);
        $this->assertSame(['Demo Super Admin', null], [$demo->fresh()->name, $demo->fresh()->phone]);
    }
}
