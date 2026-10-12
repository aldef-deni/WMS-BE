<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $t) {
            $t->string('condition')->default('sellable');
        });
        Schema::table('allocations', function (Blueprint $t) {
            $t->unsignedInteger('dispatched')->default(0);
            $t->unsignedInteger('returned')->default(0);
        });
        // Backfill existing demo dispatch history without modifying ledger or balances.
        foreach (DB::table('document_lines')->where('dispatched', '>', 0)->get() as $line) {
            $left = $line->dispatched;
            foreach (DB::table('allocations')->where('document_line_id', $line->id)->orderBy('id')->get() as $a) {
                if (! $left) {
                    break;
                }$shipped = min($left, $line->dispatched);
                DB::table('allocations')->where('id', $a->id)->update(['dispatched' => $shipped]);
                $left -= $shipped;
            }
        }
    }

    public function down(): void
    {
        Schema::table('document_lines', fn (Blueprint $t) => $t->dropColumn('condition'));
        Schema::table('allocations', fn (Blueprint $t) => $t->dropColumn(['dispatched', 'returned']));
    }
};
