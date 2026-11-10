<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Visa product need to add the Documents checklist and includes and
 * excludes visa services in each visa" — documents_required already
 * existed but had no admin UI; this migration adds the two remaining
 * checklist-style lists so a visa product can advertise exactly what's
 * bundled (e.g. "Visa fee", "Courier of passport") versus what isn't
 * (e.g. "Photography", "Travel insurance"), same free-text-array shape
 * as documents_required so the same admin UI component can manage all
 * three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visa_products', function (Blueprint $table) {
            $table->json('included_services')->nullable()->after('documents_required');
            $table->json('excluded_services')->nullable()->after('included_services');
        });
    }

    public function down(): void
    {
        Schema::table('visa_products', function (Blueprint $table) {
            $table->dropColumn(['included_services', 'excluded_services']);
        });
    }
};
