<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contractor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_name');
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('Canada');
            $table->string('phone')->nullable();
            $table->string('tax_number')->nullable();
            $table->decimal('tax_rate', 5, 4)->default(0.05);
            $table->string('payment_terms')->default('Net 15 days');
            $table->string('invoice_number_pattern')->default('{MM}-{seq}');
            $table->text('harvest_access_token')->nullable();
            $table->string('harvest_account_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_profiles');
    }
};
