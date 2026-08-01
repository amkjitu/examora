<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();

            // The session being forecast. Predictions are always for one specific
            // future sitting, never "in general".
            $table->unsignedSmallInteger('target_year');

            // P(at least one question from this topic appears), 0..1.
            $table->decimal('appearance_probability', 5, 4)->default(0);

            // Poisson mean and an interval widened by how little we know.
            $table->decimal('expected_questions', 6, 3)->default(0);
            $table->decimal('expected_low', 6, 3)->default(0);
            $table->decimal('expected_high', 6, 3)->default(0);

            // How much the data supports this at all, 0..1. Thin or irregular
            // history means low confidence, and the UI must say so.
            $table->decimal('confidence', 5, 4)->default(0);

            $table->string('trend', 12)->default('steady');   // rising | steady | falling
            $table->decimal('overdue_ratio', 6, 3)->default(0); // years since last ÷ mean gap
            $table->unsignedTinyInteger('rank')->nullable();

            // The raw numbers behind the forecast, and the sentences the UI shows.
            // Stored so a prediction can always be explained after the fact, even
            // if the model changes underneath it.
            $table->json('signals')->nullable();
            $table->json('evidence')->nullable();

            $table->string('model_version', 16)->default('v1');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'topic_id', 'target_year']);
            $table->index(['exam_id', 'target_year', 'appearance_probability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_predictions');
    }
};
