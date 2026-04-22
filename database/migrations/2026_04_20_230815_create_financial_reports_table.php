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
        Schema::create('financial_reports', function (Blueprint $table) {

            $table->id();
            $table->string('title');
            $table->string('source_agency');          // e.g. NIMC, CBN, FIRS
            $table->enum('report_type', ['budget', 'audit', 'revenue', 'expenditure', 'compliance', 'quarterly', 'annual']);
            $table->string('fiscal_year', 10);
            $table->string('quarter', 5)->nullable();  // Q1–Q4
            $table->string('file_path')->nullable();   // PDF storage path
            $table->string('file_hash')->nullable();   // SHA256 for integrity
            $table->enum('status', ['pending', 'processing', 'validated', 'flagged', 'rejected', 'approved'])
                ->default('pending');
            $table->json('raw_metadata')->nullable();  // original extracted fields
            $table->json('normalized_data')->nullable(); // AI-normalized fields
            $table->decimal('total_amount', 20, 2)->nullable();
            $table->string('currency', 5)->default('NGN');
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_reports');
    }
};
