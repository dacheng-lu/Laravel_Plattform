<?php

namespace App\Support;

use App\MistakeSource;
use App\MistakeType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The mistake types of a class for the teacher's overview: how they are distributed per competency and which one
 * dominates a group. Read-only aggregates over answers the teacher may see anyway – they never change a score.
 */
class MistakeInsights
{
    /**
     * How often each mistake type occurs per competency, counted over the classified answers of the given students and
     * split by who classified: the platform's rules ("rule"), a model ("ai") and the teacher's own order ("teacher").
     * The three never mix, so the overview marks a rule's and a model's counts as a hint that may be wrong and the
     * teacher's own counts as an order without that caveat.
     *
     * Only answers from lessons of classes the viewer may see are counted, so a student who moved to another school
     * does not carry the old school's mistakes into the new teacher's overview.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{rule: array<string, int>, ai: array<string, int>, teacher: array<string, int>}>
     */
    public static function distributionByCompetency(User $viewer, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $rows = DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->join('questions', 'questions.id', '=', 'answers.question_id')
            ->join('lessons', 'lessons.id', '=', 'submissions.lesson_id')
            ->whereIn('submissions.student_id', $studentIds)
            ->whereIn('lessons.school_class_id', LearningInsights::visibleClasses($viewer)->select('id'))
            ->whereNotNull('answers.mistake_type')
            ->whereNotNull('questions.competency_id')
            ->groupBy('questions.competency_id', 'answers.mistake_type', 'answers.mistake_source')
            ->selectRaw('questions.competency_id as competency_id, answers.mistake_type as mistake_type, answers.mistake_source as mistake_source, count(*) as mistake_count')
            ->get();

        $distribution = [];

        foreach ($rows as $row) {
            // An unknown value is skipped instead of breaking the overview: only the enum says what may be shown.
            if (MistakeType::tryFrom((string) $row->mistake_type) === null) {
                continue;
            }

            // Only the teacher's own order counts as an order; the offline rules and every model stay a hint, and an
            // unknown or missing source counts as a model's hint, never as the teacher's word.
            $bucket = match (MistakeSource::tryFrom((string) $row->mistake_source)) {
                MistakeSource::Teacher => 'teacher',
                MistakeSource::Demo => 'rule',
                default => 'ai',
            };

            $distribution[(int) $row->competency_id][$bucket][(string) $row->mistake_type] = (int) $row->mistake_count;
        }

        return $distribution;
    }

    /**
     * The mistake type most of the given students made in this competency, in the lessons the gap showed in.
     * A tie keeps the order of MistakeType::cases(), so the result is deterministic.
     * Null when nothing was classified.
     *
     * @param  list<int>  $studentIds
     * @param  list<int>  $lessonIds
     */
    public static function mostCommon(array $studentIds, int $competencyId, array $lessonIds): ?MistakeType
    {
        if ($studentIds === [] || $lessonIds === []) {
            return null;
        }

        $rows = DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->join('questions', 'questions.id', '=', 'answers.question_id')
            ->whereIn('submissions.student_id', $studentIds)
            ->whereIn('submissions.lesson_id', $lessonIds)
            ->where('questions.competency_id', $competencyId)
            ->whereNotNull('answers.mistake_type')
            ->groupBy('answers.mistake_type')
            ->selectRaw('answers.mistake_type as mistake_type, count(*) as mistake_count')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->mistake_type] = (int) $row->mistake_count;
        }

        $mostCommon = null;
        $highestCount = 0;

        foreach (MistakeType::cases() as $type) {
            $count = $counts[$type->value] ?? 0;

            if ($count > $highestCount) {
                $highestCount = $count;
                $mostCommon = $type;
            }
        }

        return $mostCommon;
    }
}
