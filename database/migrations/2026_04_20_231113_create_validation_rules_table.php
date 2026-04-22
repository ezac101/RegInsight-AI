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
        Schema::create('validation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('validation_rule_id')->constrained();
            $table->foreignId('report_field_id')->nullable()->constrained(); // specific field if applicable
            $table->text('violation_detail');           // what exactly triggered the rule
            $table->text('ai_explanation')->nullable(); // LLM-generated plain English explanation
            $table->float('ai_confidence')->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'overridden'])->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_rules');
    }
};
