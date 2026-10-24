<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Heute wiederholen": one row per student and question, holding only the Leitner box, the next due date and
     * three counters. Single attempts are not stored (data minimisation), and self-assessments never touch scores.
     */
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            // A question carries its lesson, so the retention period and a deleted lesson remove the review item too.
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('box')->default(1);
            $table->date('due_on');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->unsignedSmallInteger('knew_count')->default(0);
            $table->unsignedSmallInteger('hard_count')->default(0);
            $table->unsignedSmallInteger('forgot_count')->default(0);
            $table->timestamps();

            $table->unique(['student_id', 'question_id']);
            $table->index(['student_id', 'due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_items');
    }
};
