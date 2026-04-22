<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Individual obligations extracted per submission (from RAG pipeline)
        Schema::create('gaid_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gaid_submission_id')->constrained()->cascadeOnDelete();
            $table->string('clause_reference');              // e.g. "GAID 2025 §4.3.1"
            $table->string('obligation_title');
            $table->text('obligation_description');
            $table->text('plain_language_explanation');      // Claude-generated plain English
            $table->date('deadline')->nullable();
            $table->string('penalty_exposure')->nullable();  // e.g. "₦10M or 2% revenue"
            $table->enum('category', ['dpo', 'dpia', 'car', 'breach', 'consent',
                                      'retention', 'registration', 'transfer', 'other']);
            $table->boolean('is_mandatory')->default(true);
            $table->integer('priority')->default(100);       // lower = higher priority
            $table->timestamps();
        });

        // Gap analysis result per obligation per submission
        Schema::create('gaid_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gaid_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gaid_obligation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gaid_document_id')->nullable()->constrained(); // evidence doc

            $table->enum('status', ['covered', 'partial', 'not_evidenced', 'not_applicable']);
            $table->float('evidence_confidence')->default(0); // 0–1
            $table->text('gap_detail')->nullable();           // what's missing
            $table->text('ai_recommendation')->nullable();    // what to do about it
            $table->string('risk_level')->nullable();         // high / medium / low
            $table->timestamps();
        });

        // Generated CAR draft document per submission
        Schema::create('gaid_car_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gaid_submission_id')->constrained()->cascadeOnDelete();
            $table->string('file_path')->nullable();          // generated PDF path
            $table->json('car_data');                         // structured CAR content
            $table->string('ndpc_template_version')->default('2025-v1');
            $table->decimal('compliance_score', 5, 2)->nullable();
            $table->enum('status', ['draft', 'reviewed', 'submitted'])->default('draft');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaid_car_drafts');
        Schema::dropIfExists('gaid_gaps');
        Schema::dropIfExists('gaid_obligations');
    }
};
