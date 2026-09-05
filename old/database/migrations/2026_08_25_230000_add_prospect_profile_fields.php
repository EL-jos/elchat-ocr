<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $table) {
            $table->string('address')->nullable()->after('location');
            $table->string('contact_person')->nullable()->after('address');
            $table->text('other_contact')->nullable()->after('contact_person');
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $table) {
            $table->dropColumn(['address', 'contact_person', 'other_contact']);
        });
    }
};
