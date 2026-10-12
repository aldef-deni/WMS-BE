<?php

namespace App\Http\Controllers;

use App\Http\Resources\RecordResource;
use App\Models\Catalog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MasterController extends Controller
{
    public function staff(Request $r)
    {
        abort_unless($r->user()->allowed('picking.assign'), 403);

        return ['data' => User::with('roles', 'clients', 'warehouses')->get()->filter(fn ($u) => $u->allowed('picking.execute') && ($r->user()->hasRole('Super Admin') || $u->clients->pluck('id')->intersect($r->user()->clients->pluck('id'))->isNotEmpty()))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()];
    }

    public function wave(Request $r)
    {
        abort_unless($r->user()->allowed('picking.assign'), 403);
        $data = $r->validate(['assigned_to' => 'required|integer|exists:users,id', 'document_ids' => 'required|array|min:1|max:100', 'document_ids.*' => 'required|integer|distinct']);
        $picker = User::findOrFail($data['assigned_to']);
        $wave = 'WAVE-'.now()->format('ymdHi').'-'.strtoupper(substr((string) Str::uuid(), 0, 6));

        return DB::transaction(function () use ($r, $data, $picker, $wave) {
            sort($data['document_ids']);
            foreach ($data['document_ids'] as $id) {
                $d = Document::visible($r->user())->where('type', 'order')->whereKey($id)->lockForUpdate()->firstOrFail();
                if (! in_array($d->status, ['allocated', 'backorder', 'picking']) || ! $picker->allowed('picking.execute') || ! $picker->inScope($d->client_id, $d->warehouse_id)) {
                    abort(422, 'Pesanan/picker tidak valid untuk wave.');
                }$d->update(['assigned_to' => $picker->id, 'meta' => array_merge($d->meta ?? [], ['wave_number' => $wave])]);
                Audit::record('picking.assign', $d->number, $d->client_id, $d->warehouse_id, ['wave' => $wave, 'picker' => $picker->id]);
            }

return ['data' => ['wave_number' => $wave, 'documents' => $data['document_ids']]];
        }, 5);
    }

    const MAP = ['clients' => Client::class, 'warehouses' => Warehouse::class, 'locations' => Location::class, 'products' => Product::class, 'roles' => Role::class, 'users' => User::class, 'catalogs' => Catalog::class, 'tariffs' => Tariff::class, 'settings' => Setting::class];

    public function query(Request $r, string $kind)
    {
        abort_unless(isset(self::MAP[$kind]), 404);
        $u = $r->user();
        $q = self::MAP[$kind]::query();
        if (in_array($kind, ['roles', 'users', 'settings'])) {
            abort_unless($u->allowed('settings.read'), 403);
        } elseif ($kind === 'tariffs') {
            abort_unless($u->allowed('billing.read'), 403);
        } else {
            abort_unless($u->allowed('master.read'), 403);
        }
        if (! $u->hasRole('Super Admin')) {
            switch ($kind) {
                case 'clients':$q->whereIn('id', $u->clients->pluck('id'));
                    break;
                case 'warehouses':$q->whereIn('id', $u->warehouses->pluck('id'));
                    break;
                case 'locations':$q->whereIn('warehouse_id', $u->warehouses->pluck('id'));
                    break;
                case 'products':case 'tariffs':$q->visible($u);
                    break;
                case 'users':abort_unless($u->allowed('users.manage'), 403);
            }
        }
        if ($kind === 'products') {
            $q->with('client');
        }if ($kind === 'locations') {
            $q->with('warehouse');
        }if ($kind === 'tariffs') {
            $q->with('client');
        }if ($kind === 'users') {
            $q->with('roles', 'clients', 'warehouses');
        }

return $q;
    }

    public function index(Request $r, string $kind)
    {
        $q = $this->query($r, $kind);
        foreach (['client_id', 'warehouse_id', 'kind'] as $field) {
            if ($r->filled($field) && Schema::hasColumn($q->getModel()->getTable(), $field)) {
                $q->where($field, $r->input($field));
            }
        }if ($r->filled('search')) {
            if (Schema::hasColumn($q->getModel()->getTable(), 'name')) {
                $q->where('name', 'like', '%'.substr($r->string('search'), 0, 100).'%');
            }
        }$sort = in_array($r->input('sort'), ['id', 'created_at']) ? $r->input('sort') : 'id';

        return RecordResource::collection($q->orderBy($sort, $r->input('direction') === 'desc' ? 'desc' : 'asc')->paginate(min(100, max(1, (int) $r->input('per_page', 15)))));
    }

    public function save(Request $r, string $kind, ?int $id = null)
    {
        $q = $this->query($r, $kind);
        $u = $r->user();
        $permission = in_array($kind, ['users', 'roles']) ? 'users.manage' : ($kind === 'settings' ? 'settings.write' : ($kind === 'tariffs' ? 'billing.write' : $kind.'.write'));
        abort_unless($u->allowed($permission), 403);
        // Only Super Admin may modify identities, roles, scopes, warehouse/client records, global configuration.
        if (in_array($kind, ['users', 'roles', 'settings', 'warehouses'])) {
            abort_unless($u->hasRole('Super Admin'), 403);
        }
        $model = $id ? $q->findOrFail($id) : new (self::MAP[$kind]);
        $rules = match ($kind) {
            'clients' => ['name' => 'required|string|max:150', 'code' => ['required', 'string', 'max:50', Rule::unique('clients')->ignore($id)], 'email' => 'nullable|email', 'address' => 'nullable|string|max:1000', 'active' => 'sometimes|boolean'],
            'warehouses' => ['name' => 'required|string|max:150', 'code' => ['required', 'string', 'max:50', Rule::unique('warehouses')->ignore($id)], 'address' => 'nullable|string|max:1000', 'capacity' => 'required|integer|min:1'],
            'locations' => ['warehouse_id' => 'required|integer|exists:warehouses,id', 'code' => ['required', 'string', 'max:50', Rule::unique('locations')->where('warehouse_id', $r->input('warehouse_id'))->ignore($id)], 'zone' => 'required|string|max:100', 'type' => 'required|in:storage,receiving,staging,quarantine,damaged,transit', 'capacity' => 'required|integer|min:1'],
            'products' => ['client_id' => 'required|integer|exists:clients,id', 'sku' => ['required', 'string', 'max:80', Rule::unique('products')->where('client_id', $r->input('client_id'))->ignore($id)], 'barcode' => ['required', 'string', 'max:100', Rule::unique('products')->where('client_id', $r->input('client_id'))->ignore($id)], 'name' => 'required|string|max:150', 'variant' => 'nullable|string|max:100', 'unit' => 'required|string|max:20', 'weight' => 'required|numeric|min:0', 'length' => 'sometimes|numeric|min:0', 'width' => 'sometimes|numeric|min:0', 'height' => 'sometimes|numeric|min:0', 'minimum_stock' => 'required|integer|min:0', 'serial_tracked' => 'sometimes|boolean', 'rotation' => 'required|in:FIFO,FEFO'],
            'catalogs' => ['kind' => 'required|in:packaging,courier,unit,zone', 'name' => 'required|string|max:150', 'code' => ['required', 'string', Rule::unique('catalogs')->where('kind', $r->input('kind'))->ignore($id)], 'meta' => 'nullable|array'],
            'tariffs' => ['client_id' => 'required|integer|exists:clients,id', 'service' => ['required', Rule::in(['receiving', 'storage', 'picking', 'packing', 'packaging', 'shipping', 'return']), Rule::unique('tariffs')->where('client_id', $r->input('client_id'))->ignore($id)], 'unit' => 'required|in:unit,package,unit_day', 'rate' => 'required|decimal:0,2|min:0'],
            'roles' => ['name' => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($id)], 'permissions' => 'required|array', 'permissions.*' => [Rule::in(config('permissions.all'))]],
            'users' => ['name' => 'required|string|max:150', 'email' => ['required', 'email', Rule::unique('users')->ignore($id)], 'password' => [$id ? 'nullable' : 'required', 'string', 'min:12'], 'role_ids' => 'required|array', 'role_ids.*' => 'integer|exists:roles,id', 'client_ids' => 'required|array', 'client_ids.*' => 'integer|exists:clients,id', 'warehouse_ids' => 'required|array', 'warehouse_ids.*' => 'integer|exists:warehouses,id'],
            'settings' => ['key' => ['required', 'string', Rule::in(['company', 'sla', 'numbering', 'notifications']), Rule::unique('settings')->ignore($id)], 'value' => 'required|array'],
        };
        $data = $r->validate($rules);
        if (isset($data['client_id'])) {
            abort_unless($u->inScope($data['client_id']), 403);
        }if (isset($data['warehouse_id'])) {
            abort_unless($u->inScope(null, $data['warehouse_id']), 403);
        }
        if ($kind === 'settings') {
            $r->validate(match ($data['key']) {
                'company' => ['value.name' => 'required|string|max:150'],'sla' => ['value.dispatch_hours' => 'required|integer|min:1|max:720'],'numbering' => ['value.prefix' => 'required|alpha_dash|max:20'],'notifications' => ['value.expiry_days' => 'required|integer|min:1|max:365', 'value.low_stock' => 'required|boolean']
            });
        }
        if ($id && isset($data['client_id']) && $data['client_id'] !== $model->client_id) {
            abort(422, 'Pemilik data tidak dapat diubah.');
        }if ($id && isset($data['warehouse_id']) && $data['warehouse_id'] !== $model->warehouse_id) {
            abort(422, 'Gudang lokasi tidak dapat diubah.');
        }
        if ($kind === 'tariffs') {
            $units = ['storage' => 'unit_day', 'shipping' => 'package', 'packaging' => 'package'];
            if ($data['unit'] !== ($units[$data['service']] ?? 'unit')) {
                abort(422, 'Satuan tarif tidak sesuai aturan layanan.');
            }
        }
        if ($kind === 'roles' && $id && $model->name === 'Super Admin') {
            abort(422, 'Role Super Admin dilindungi.');
        }
        if ($kind === 'users' && $id && $model->hasRole('Super Admin') && ! in_array(Role::where('name', 'Super Admin')->value('id'), $data['role_ids']) && User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->count() <= 1) {
            abort(422, 'Super Admin terakhir tidak dapat dinonaktifkan.');
        }

        return DB::transaction(function () use ($model, $kind, $data, $id, $u) {
            $fields = collect($data)->except(['role_ids', 'client_ids', 'warehouse_ids'])->all();
            if (empty($fields['password'])) {
                unset($fields['password']);
            }$model->fill($fields)->save();
            if ($kind === 'clients' && ! $id && ! $u->hasRole('Super Admin')) {
                $u->clients()->syncWithoutDetaching([$model->id]);
                $u->unsetRelation('clients');
            }if ($kind === 'users') {
                $model->roles()->sync($data['role_ids']);
                $model->clients()->sync($data['client_ids']);
                $model->warehouses()->sync($data['warehouse_ids']);
                $model->load('roles', 'clients', 'warehouses');
            }Audit::record($kind.'.'.($id ? 'update' : 'create'), (string) $model->id, $kind === 'clients' ? $model->id : ($model->client_id ?? null), $model->warehouse_id ?? null);

            return new RecordResource($model);
        });
    }

    public function permissions(Request $r)
    {
        abort_unless($r->user()->allowed('settings.read'),403);

        return ['data' => config('permissions.all')];
    }
}
