<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store payment amounts as integer minor units (kobo, cents) instead of a
     * decimal, per the SDGP payments rule. Existing rows are converted exactly.
     *
     * The conversion is inlined (not App\Support\Money) so this migration keeps
     * working whatever happens to application code later.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_minor')->nullable()->after('user_id');
        });

        DB::table('payments')->select(['id', 'amount'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('payments')->where('id', $row->id)->update([
                    'amount_minor' => $this->toMinor($row->amount),
                ]);
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_minor')->nullable(false)->change();
            $table->dropColumn('amount');
        });
    }

    /**
     * Restore the decimal column from the minor units.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('user_id');
        });

        DB::table('payments')->select(['id', 'amount_minor'])->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $minor = (int) $row->amount_minor;
                DB::table('payments')->where('id', $row->id)->update([
                    'amount' => sprintf('%d.%02d', intdiv($minor, 100), $minor % 100),
                ]);
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable(false)->change();
            $table->dropColumn('amount_minor');
        });
    }

    /**
     * Exact decimal-to-minor conversion. Drivers return decimals as strings
     * (MySQL, PostgreSQL) or numbers (SQLite); both are handled without floats
     * leaking into the stored value.
     */
    private function toMinor(mixed $amount): int
    {
        $amount = is_float($amount) ? sprintf('%.2F', $amount) : trim((string) $amount);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
};
