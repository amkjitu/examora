<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // bcs-preliminary
            $table->string('name');                    // BCS Preliminary
            $table->string('slug')->unique();
            $table->string('authority')->nullable();   // Bangladesh Public Service Commission
            $table->string('locale', 8)->default('en');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('total_marks')->default(200);
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->decimal('negative_marking', 4, 2)->default(0.50);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedSmallInteger('marks')->default(0);   // marks this subject carries in the exam
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'slug']);
        });

        // Topics are self-referencing. A root topic is a "topic"; its children are
        // "chapters" — the atomic learnable unit a lesson is generated for. This
        // keeps the hierarchy flexible per exam without a rigid fourth table.
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('topics')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('level', 16)->default('chapter');     // topic | chapter
            $table->text('description')->nullable();
            $table->string('syllabus_ref')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subject_id', 'slug']);
            $table->index(['parent_id', 'position']);
        });

        Schema::create('past_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('session_label')->nullable();          // "44th BCS Preliminary"
            $table->text('stem');
            $table->json('options');                              // ["A text", "B text", ...]
            $table->unsignedTinyInteger('correct_index');
            $table->text('explanation')->nullable();
            $table->string('difficulty', 8)->default('medium');   // easy | medium | hard
            $table->decimal('marks', 4, 2)->default(1);
            $table->string('source')->nullable();
            $table->string('fingerprint', 64)->nullable();        // dedupe identical questions across years
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'topic_id', 'year']);
            $table->index('fingerprint');
        });

        // Denormalised exam-intelligence layer. Recomputed by a scheduled job;
        // this is the table that powers "probability ★★★★★" in the UI.
        Schema::create('topic_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedSmallInteger('first_year')->nullable();
            $table->unsignedSmallInteger('last_year')->nullable();
            $table->json('years')->nullable();                    // {"2018": 3, "2021": 5}
            $table->decimal('frequency_per_year', 6, 3)->default(0);
            $table->decimal('recency_score', 5, 4)->default(0);    // 0..1
            $table->decimal('importance_score', 5, 2)->default(0); // 0..100
            $table->unsignedTinyInteger('probability_stars')->default(1);
            $table->unsignedTinyInteger('expected_questions_min')->default(0);
            $table->unsignedTinyInteger('expected_questions_max')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->default(5);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'topic_id']);
            $table->index(['exam_id', 'importance_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_statistics');
        Schema::dropIfExists('past_questions');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('exams');
    }
};
