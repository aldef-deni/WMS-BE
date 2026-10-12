<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('address')->nullable();
            $t->unsignedInteger('capacity')->default(10000);
            $t->timestamps();
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('code');
            $t->string('zone');
            $t->enum('type', ['storage', 'receiving', 'staging', 'quarantine', 'damaged', 'transit']);
            $t->unsignedInteger('capacity')->default(1000);
            $t->timestamps();
            $t->unique(['warehouse_id', 'code']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->string('sku');
            $t->string('barcode');
            $t->string('name');
            $t->string('variant')->nullable();
            $t->string('unit')->default('pcs');
            $t->decimal('weight', 12, 3)->default(0);
            $t->decimal('length', 12, 2)->default(0);
            $t->decimal('width', 12, 2)->default(0);
            $t->decimal('height', 12, 2)->default(0);
            $t->unsignedInteger('minimum_stock')->default(10);
            $t->boolean('serial_tracked')->default(false);
            $t->enum('rotation', ['FIFO', 'FEFO'])->default('FEFO');
            $t->timestamps();
            $t->unique(['client_id', 'sku']);
            $t->unique(['client_id', 'barcode']);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->json('permissions');
            $t->timestamps();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->restrictOnDelete();
            $t->primary(['user_id', 'role_id']);
        });
        foreach (['client', 'warehouse'] as $scope) {
            Schema::create($scope.'_user', function (Blueprint $t) use ($scope) {
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId($scope.'_id')->constrained()->restrictOnDelete();
                $t->primary(['user_id', $scope.'_id']);
            });
        }
        Schema::create('balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('batch')->default('');
            $t->string('serial')->default('');
            $t->date('expiry')->nullable();
            $t->enum('condition', ['sellable', 'quarantine', 'damaged', 'transit'])->default('sellable');
            $t->unsignedInteger('quantity')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->timestamps();
            $t->unique(['location_id', 'product_id', 'batch', 'serial', 'condition'], 'balance_identity');
            $t->index(['client_id', 'warehouse_id', 'product_id']);
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->enum('type', ['inbound', 'order', 'transfer', 'return', 'count', 'adjustment', 'manifest']);
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('reference_id')->nullable()->constrained('documents')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status')->default('draft')->index();
            $t->string('priority')->default('normal');
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('dispatched_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'warehouse_id', 'type']);
        });
        Schema::create('document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('batch')->default('');
            $t->string('serial')->default('');
            $t->date('expiry')->nullable();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('received')->default(0);
            $t->unsignedInteger('putaway')->default(0);
            $t->unsignedInteger('allocated')->default(0);
            $t->unsignedInteger('picked')->default(0);
            $t->unsignedInteger('packed')->default(0);
            $t->unsignedInteger('dispatched')->default(0);
            $t->unsignedInteger('returned')->default(0);
            $t->integer('counted')->nullable();
            $t->integer('snapshot')->nullable();
            $t->unsignedBigInteger('cutoff_ledger_id')->nullable();
            $t->timestamps();
        });
        Schema::create('allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('balance_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('picked')->default(0);
            $t->timestamps();
            $t->unique(['document_line_id', 'balance_id']);
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('balance_id')->constrained()->restrictOnDelete();
            $t->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('event');
            $t->integer('quantity_delta');
            $t->integer('reserved_delta');
            $t->unsignedInteger('quantity_after');
            $t->unsignedInteger('reserved_after');
            $t->timestamp('created_at');
            $t->index(['client_id', 'warehouse_id', 'created_at']);
        });
        Schema::create('catalogs', function (Blueprint $t) {
            $t->id();
            $t->string('kind');
            $t->string('code');
            $t->string('name');
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'code']);
        });
        Schema::create('tariffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->string('service');
            $t->string('unit');
            $t->decimal('rate', 18, 2);
            $t->timestamps();
            $t->unique(['client_id', 'service']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->date('due_date');
            $t->decimal('total', 18, 2);
            $t->decimal('paid', 18, 2)->default(0);
            $t->string('status')->default('unpaid');
            $t->timestamps();
        });
        Schema::create('charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('activity_key')->unique();
            $t->string('service');
            $t->string('unit');
            $t->decimal('rate', 18, 2);
            $t->decimal('units', 18, 3);
            $t->decimal('amount', 18, 2);
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('reference')->unique();
            $t->date('paid_at');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action');
            $t->string('subject');
            $t->json('details')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('key', 100);
            $t->string('operation');
            $t->string('request_hash', 64);
            $t->json('response')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'key']);
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('path');
            $t->string('name');
            $t->string('mime');
            $t->timestamps();
        });
        Schema::create('tracking_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('status');
            $t->text('description');
            $t->boolean('simulated')->default(true);
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'tracking_events', 'attachments', 'idempotency_keys', 'audit_logs', 'payments', 'charges', 'invoices', 'tariffs', 'catalogs', 'ledger_entries', 'allocations', 'document_lines', 'documents', 'balances', 'warehouse_user', 'client_user', 'role_user', 'roles', 'products', 'locations', 'warehouses', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
