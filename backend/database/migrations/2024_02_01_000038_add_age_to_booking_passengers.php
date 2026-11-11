<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "During the passenger filling need age column in bus, Cab booking,
 * Hotel" — a plain numeric age, captured alongside name (and, on Bus,
 * title/gender) at passenger-details time. Deliberately separate from
 * the existing `dob` column: `dob` is a full date picker Flight alone
 * uses for passport/MRZ purposes on international travel, while these
 * three domestic, short-form verticals just want a simple age number,
 * with no passport concept at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable()->after('dob');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn('age');
        });
    }
};
