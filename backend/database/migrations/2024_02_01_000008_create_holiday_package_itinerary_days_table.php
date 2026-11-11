<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-by-day itinerary for a holiday package (title + description per
 * day), matching the "Day wise itinerary" structure found on reference
 * DMC sites (e.g. Journey Meister's package detail pages). No timestamps,
 * same as BookingSegment — these rows are catalog content, not
 * independently auditable events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_package_itinerary_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_package_id')->constrained('holiday_packages')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->string('title', 255);
            $table->text('description')->nullable();

            // Explicit short name — MySQL's default 64-char identifier limit
            // rejects Laravel's auto-generated name for this table/column
            // combination ("holiday_package_itinerary_days_holiday_package_
            // id_day_number_unique" is 69 chars).
            $table->unique(['holiday_package_id', 'day_number'], 'hp_itinerary_days_pkg_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_package_itinerary_days');
    }
};
