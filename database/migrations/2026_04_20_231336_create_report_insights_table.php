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
        Schema::create('report_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_report_id')->constrained()->cascadeOnDelete();
            $table->enum('insight_type', ['summary', 'anomaly', 'trend', 'risk', 'recommendation']);
            $table->text('content');                     // AI-generated plain English
            $table->json('supporting_data')->nullable(); // data references used
            $table->float('confidence_score')->default(0);
            $table->string('model_used')->nullable();
            $table->boolean('is_shown_on_dashboard')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_insights');
    }
};
