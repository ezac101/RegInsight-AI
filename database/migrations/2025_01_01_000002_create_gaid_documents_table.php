<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaid_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gaid_submission_id')->constrained()->cascadeOnDelete();

            $table->string('parastatal');                   // which agency this doc belongs to
            $table->enum('document_type', [
                'privacy_policy',
                'data_handling_policy',
                'dpia_report',
                'dpo_appointment_letter',
                'breach_response_plan',
                'data_retention_policy',
                'consent_framework',
                'vendor_agreement',
                'other',
            ]);
            $table->string('document_label')->nullable();   // user-provided name
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_hash');                    // SHA-256 integrity
            $table->unsignedInteger('file_size_kb');
            $table->enum('mime_type', ['application/pdf', 'application/msword',
                                       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                       'text/plain']);

            // AI analysis output
            $table->text('extracted_text')->nullable();     // PyMuPDF output
            $table->json('ai_analysis')->nullable();        // Claude structured analysis
            $table->json('clauses_covered')->nullable();    // which GAID clauses this doc covers
            $table->float('coverage_score')->default(0);    // 0–1

            $table->enum('processing_status', ['pending', 'processing', 'analysed', 'failed'])
                  ->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaid_documents');
    }
};
