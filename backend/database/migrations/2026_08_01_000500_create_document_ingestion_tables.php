<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('path');
            $table->string('mime', 64)->default('application/pdf');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->unsignedSmallInteger('pages_processed')->default(0);

            // The quarantine boundary.
            //
            //   shared  — admin-uploaded, verified material. Once approved it
            //             enters past_questions and moves the exam intelligence
            //             every student sees.
            //   private — a student's own upload. It can drive lessons and
            //             practice for that student alone, and can never reach
            //             the shared corpus without an admin promoting it.
            //
            // Without this split, one student uploading a coaching centre's
            // guesswork would corrupt the importance scores for everyone.
            $table->string('visibility', 16)->default('private');

            $table->string('doc_type', 24)->default('question_paper'); // question_paper | book | notes
            $table->string('status', 24)->default('pending');          // pending | processing | extracted | failed
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('session_label')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'visibility', 'status']);
            $table->index(['user_id', 'created_at']);
            // Re-uploading the same file should be recognised, not re-billed.
            $table->unique(['user_id', 'checksum']);
        });

        // Page text is kept so a reviewer can see the passage a question was
        // extracted from, and so re-extraction never re-reads (or re-pays for)
        // a page that is already transcribed.
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            $table->longText('text')->nullable();
            $table->string('extraction_method', 24)->nullable(); // text_layer | vision
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamps();

            $table->unique(['source_document_id', 'page_number']);
        });

        // Staging. Nothing here is trusted until a human approves it.
        Schema::create('extracted_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('page_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('session_label')->nullable();
            $table->text('stem');
            $table->json('options');
            $table->unsignedTinyInteger('correct_index')->nullable();
            $table->text('explanation')->nullable();
            $table->string('difficulty', 8)->default('medium');

            // The model's own confidence, and the classifier's separately: a
            // clean transcription of a question whose topic is a guess is a very
            // different review problem from a garbled transcription.
            $table->decimal('confidence', 4, 3)->nullable();
            $table->decimal('topic_confidence', 4, 3)->nullable();

            $table->string('status', 16)->default('pending'); // pending | approved | rejected | duplicate
            $table->string('fingerprint', 64)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('past_questions')->nullOnDelete();
            $table->foreignId('past_question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['source_document_id', 'status']);
            $table->index(['exam_id', 'status']);
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_questions');
        Schema::dropIfExists('document_pages');
        Schema::dropIfExists('source_documents');
    }
};
