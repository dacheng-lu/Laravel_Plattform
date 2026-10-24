<?php

namespace App\Models;

use Database\Factories\ReviewItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question a student practises again in "Heute wiederholen": its Leitner box, the next due date and three
 * counters. Single attempts are not stored (data minimisation).
 */
#[Fillable(['student_id', 'question_id', 'box', 'due_on', 'last_reviewed_at', 'knew_count', 'hard_count', 'forgot_count'])]
class ReviewItem extends Model
{
    /** @use HasFactory<ReviewItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'box' => 'integer',
            'due_on' => 'date',
            'last_reviewed_at' => 'datetime',
            'knew_count' => 'integer',
            'hard_count' => 'integer',
            'forgot_count' => 'integer',
        ];
    }
}
