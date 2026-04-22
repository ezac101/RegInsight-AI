<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_report_id')->constrained()->cascadeOnDelete();
            $table->string('original_key');           // raw key from source e.g. "TotalExp"
            $table->string('normalized_key');          // AI-mapped key e.g. "total_expenditure"
            $table->text('original_value');
            $table->text('normalized_value')->nullable();
            $table->string('field_type')->nullable();  // currency, date, percentage, text
            $table->float('ai_confidence')->default(0); // 0.0–1.0
            $table->boolean('is_flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_fields');
    }
};
