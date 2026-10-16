<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit;
use App\Services\DemoReset;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    private const AVATAR_SIZE = 320;

    public function show(Request $r)
    {
        $u = $r->user();
        $demo = DemoReset::isDemo($u->email);

        return ['data' => [
            'user' => $u->load('roles', 'clients', 'warehouses'),
            'permissions' => $u->permissions(),
            'super_admin' => $u->hasRole('Super Admin'),
            'demo' => $demo,
            'demo_reset_at' => $demo ? app(DemoReset::class)->state()?->reset_due_at : null,
            'sessions_supported' => config('session.driver') === 'database',
        ]];
    }

    public function update(Request $r)
    {
        $u = $r->user();
        $data = $r->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($u->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]*$/'],
            'job_title' => 'nullable|string|max:100',
        ], ['phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, +, -, dan tanda kurung.']);
        if (DemoReset::isDemo($u->email) && strtolower($data['email']) !== strtolower($u->email)) {
            throw ValidationException::withMessages(['email' => 'Email akun demo tidak dapat diubah.']);
        }
        $u->update($data);
        Audit::record('profile.update', (string) $u->id, null, null, ['fields' => array_keys($u->getChanges())]);

        return $this->session($r);
    }

    public function password(Request $r)
    {
        $u = $r->user();
        $this->denyDemo($u, 'Password akun demo tidak dapat diubah.');
        $data = $r->validate([
            'current_password' => 'required|string|current_password:web',
            'password' => 'required|string|min:12|confirmed|different:current_password',
        ], [
            'current_password.current_password' => 'Password saat ini tidak sesuai.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ]);
        $u->update(['password' => $data['password']]);
        $this->deleteOtherSessions($r);
        Audit::record('profile.password', (string) $u->id);

        return response()->noContent();
    }

    public function avatar(Request $r)
    {
        $u = $r->user();
        $r->validate(['avatar' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:3072|dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'], [
            'avatar.image' => 'File harus berupa gambar.',
            'avatar.mimes' => 'Format foto harus JPG, PNG, atau WEBP.',
            'avatar.max' => 'Ukuran foto maksimal 3 MB.',
            'avatar.dimensions' => 'Resolusi foto minimal 64 × 64 piksel.',
        ]);
        $path = $this->storeAvatar($r->file('avatar'), $u->id);
        $old = $u->avatar_path;
        $u->forceFill(['avatar_path' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
        Audit::record('profile.avatar', (string) $u->id);

        return $this->session($r);
    }

    public function removeAvatar(Request $r)
    {
        $u = $r->user();
        if ($u->avatar_path) {
            Storage::disk('local')->delete($u->avatar_path);
            $u->forceFill(['avatar_path' => null])->save();
            Audit::record('profile.avatar_removed', (string) $u->id);
        }

        return $this->session($r);
    }

    public function avatarFile(int $id)
    {
        $path = User::whereKey($id)->value('avatar_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=604800']);
    }

    public function activity(Request $r)
    {
        return ['data' => AuditLog::where('user_id', $r->user()->id)->latest('id')->limit(30)->get(['id', 'action', 'subject', 'created_at'])];
    }

    public function sessions(Request $r)
    {
        if (config('session.driver') !== 'database') {
            return ['data' => []];
        }
        $current = $r->session()->getId();

        return ['data' => DB::table('sessions')->where('user_id', $r->user()->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])->map(fn ($s) => [
            'id' => substr(hash('sha256', $s->id), 0, 16),
            'current' => $s->id === $current,
            'ip_address' => $s->ip_address,
            'user_agent' => Str::limit((string) $s->user_agent, 180),
            'last_activity' => date(DATE_ATOM, $s->last_activity),
        ])];
    }

    public function logoutOtherSessions(Request $r)
    {
        $u = $r->user();
        $this->denyDemo($u, 'Akun demo dipakai bersama; sesi perangkat lain tidak dapat diakhiri.');
        $r->validate(['current_password' => 'required|string|current_password:web'], ['current_password.current_password' => 'Password saat ini tidak sesuai.']);
        $count = $this->deleteOtherSessions($r);
        Audit::record('profile.logout_other_sessions', (string) $u->id, null, null, ['sessions' => $count]);

        return ['data' => ['ended' => $count]];
    }

    private function session(Request $r): array
    {
        $u = $r->user()->fresh();

        return ['user' => $u->load('roles', 'clients', 'warehouses'), 'permissions' => $u->permissions(), 'super_admin' => $u->hasRole('Super Admin'), 'demo' => DemoReset::isDemo($u->email)];
    }

    private function denyDemo(User $u, string $message): void
    {
        if (DemoReset::isDemo($u->email)) {
            throw ValidationException::withMessages(['account' => $message]);
        }
    }

    private function deleteOtherSessions(Request $r): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table('sessions')->where('user_id', $r->user()->id)->where('id', '!=', $r->session()->getId())->delete();
    }

    /** Center-crop and resize to a square JPEG so stored avatars stay small; falls back to the original file without GD. */
    private function storeAvatar(UploadedFile $file, int $userId): string
    {
        $name = 'avatars/'.$userId.'-'.Str::lower(Str::random(12));
        $source = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($file->getRealPath())) : false;
        if (! $source) {
            return $file->storeAs(dirname($name), basename($name).'.'.$file->extension(), 'local');
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, $h);
        $size = min(self::AVATAR_SIZE, $side);
        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagejpeg($canvas, null, 86);
        $jpeg = (string) ob_get_clean();
        Storage::disk('local')->put($name.'.jpg', $jpeg);

        return $name.'.jpg';
    }
}
