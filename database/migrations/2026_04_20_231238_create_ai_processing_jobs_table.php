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
        Schema::create('ai_processing_jobs', function (Blueprint $table) {

            $table->id();
            $table->foreignId('financial_report_id')->constrained()->cascadeOnDelete();
            $table->enum('job_type', ['normalize', 'extract', 'deduplicate', 'insight', 'explain']);
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->string('python_version')->nullable();
            $table->string('model_used')->nullable();     // e.g. claude-sonnet-4-20250514
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_processing_jobs');
    }
};
