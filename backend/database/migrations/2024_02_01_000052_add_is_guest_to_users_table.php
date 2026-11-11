<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest checkout no longer turns the guest's email/phone into a real,
 * signed-in account (see GuestCheckoutService). Each guest booking gets a
 * private "guest" user with no email, phone or password that only owns
 * that booking, so it can never be used to sign in or collide with an
 * existing account's email or phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_guest');
        });
    }
};
