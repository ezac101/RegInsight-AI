<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Core org submission — one row per organisation assessment session
        Schema::create('gaid_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique();        // e.g. GAID-2025-0042
            $table->string('organisation_name');
            $table->string('organisation_email');
            $table->string('parastatal');                      // e.g. CBN, NIMC, NNPC, FIRS, NBS...
            $table->string('sector');                          // fintech, health, e-commerce, etc.
            $table->unsignedBigInteger('data_subjects');       // number of data subjects
            $table->boolean('uses_ai')->default(false);
            $table->boolean('processes_sensitive_data')->default(false);
            $table->boolean('transfers_data_outside_nigeria')->default(false);
            $table->json('questionnaire_answers');             // raw Q&A from form

            // OPA classification output (deterministic)
            $table->enum('dcpmi_tier', ['ultra_high', 'extra_high', 'ordinary_high', 'not_classified'])
                  ->nullable();
            $table->decimal('car_filing_fee', 15, 2)->nullable();
            $table->date('filing_deadline')->nullable();
            $table->boolean('dpo_required')->nullable();
            $table->boolean('dpia_required')->nullable();
            $table->json('opa_decision')->nullable();          // full OPA output JSON

            // AI obligation extraction output (RAG)
            $table->json('obligations')->nullable();           // array of obligation objects
            $table->json('gap_analysis')->nullable();          // gap comparison results
            $table->decimal('compliance_score', 5, 2)->nullable(); // 0–100
            $table->enum('status', ['draft', 'classified', 'obligations_extracted',
                                    'gap_analysed', 'car_generated', 'submitted'])
                  ->default('draft');

            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('car_generated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaid_submissions');
    }
};
