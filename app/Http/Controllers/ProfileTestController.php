<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ProfileTestOption;
use App\Models\ProfileTestQuestion;
use App\Support\ArchetypeCatalog;
use App\Support\ArchetypeScorer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Profile Test — a self-service personality instrument (ported from the Hiring
 * platform, minus the games). Employees take their own test; the result feeds
 * the Personality card on their profile. HR/management edit the question bank.
 */
class ProfileTestController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const ANIMALS = ['rabbit', 'tortoise', 'fox', 'sloth'];

    /**
     * Self-service take screen: the signed-in employee answers their own test
     * and sees their computed archetype.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $result = $employee?->profileTestResult;

        return [
            'working' => $this->questions('working_style'),
            'colour' => $this->questions('colour'),
            'result' => $result,
            'archetype' => $result?->animal_archetype ? ArchetypeCatalog::get($result->animal_archetype) : null,
            'canSubmit' => (bool) $employee,
        ];
    }

    /**
     * HR/management question-bank editor.
     *
     * @return array<string, mixed>
     */
    public function adminData(Request $request): array
    {
        return [
            'working' => $this->questions('working_style'),
            'colour' => $this->questions('colour'),
        ];
    }

    /** The signed-in employee saves their own answers; scoring is recomputed. */
    public function submit(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $validated = $request->validate([
            'self_goal' => ['nullable', 'string', 'max:2000'],
            'self_strengths' => ['nullable', 'string', 'max:2000'],
            'self_weaknesses' => ['nullable', 'string', 'max:2000'],
            'self_interests' => ['nullable', 'string', 'max:2000'],
            'self_mbti' => ['nullable', 'string', 'max:10'],
            'working_style' => ['nullable', 'array'],
            'working_style.*' => ['nullable', 'integer'],
            'colour' => ['nullable', 'array', 'max:100'],
            'colour.*' => ['nullable', 'string', 'max:1000'],
        ]);

        // [question_id => option_id], empty answers stripped.
        $workingAnswers = array_filter((array) ($validated['working_style'] ?? []));

        $options = $workingAnswers
            ? ProfileTestOption::whereIn('id', array_values($workingAnswers))->get()->keyBy('id')
            : collect();

        $animals = [];
        foreach ($workingAnswers as $questionId => $optionId) {
            $opt = $options->get($optionId);
            // Only count the option if it truly belongs to the question it was submitted under.
            if ($opt && (int) $opt->profile_test_question_id === (int) $questionId && $opt->animal) {
                $animals[] = $opt->animal;
            }
        }

        $scored = ArchetypeScorer::score($animals);

        $employee->profileTestResult()->updateOrCreate([], [
            'self_goal' => $validated['self_goal'] ?? null,
            'self_strengths' => $validated['self_strengths'] ?? null,
            'self_weaknesses' => $validated['self_weaknesses'] ?? null,
            'self_interests' => $validated['self_interests'] ?? null,
            'self_mbti' => $validated['self_mbti'] ?? null,
            'working_style_answers' => $workingAnswers,
            'colour_answers' => (array) $request->input('colour', []),
            'animal_archetype' => $scored['archetype'],
            'totals' => $scored['totals'],
            'submitted_at' => now(),
        ]);

        // Mirror the result into the Personality card on the profile screen.
        $this->syncPersonality($employee, $scored, $validated['self_mbti'] ?? null);

        AuditLog::record('Completed profile test', $employee->name);

        return back()->with('ok', 'Profile test saved.');
    }

    /** Privileged: add a question (+ its options for working-style). */
    public function storeQuestion(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateQuestion($request);

        $position = (ProfileTestQuestion::where('section', $data['section'])->max('position') ?? 0) + 1;

        $question = ProfileTestQuestion::create([
            'section' => $data['section'],
            'prompt_en' => trim($data['prompt']),
            'position' => $position,
        ]);

        $this->syncOptions($question, $data);

        AuditLog::record('Added profile test question', $question->prompt_en);

        return back()->with('ok', 'Question added.');
    }

    /** Privileged: edit a question (prompt, section, options). */
    public function updateQuestion(Request $request, ProfileTestQuestion $question): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateQuestion($request);

        $attrs = ['section' => $data['section'], 'prompt_en' => trim($data['prompt'])];
        if ($data['section'] !== $question->section) {
            $attrs['position'] = (ProfileTestQuestion::where('section', $data['section'])->max('position') ?? 0) + 1;
        }

        $question->update($attrs);
        $this->syncOptions($question, $data);

        AuditLog::record('Updated profile test question', $question->prompt_en);

        return back()->with('ok', 'Question updated.');
    }

    /** Privileged: remove a question (options cascade). */
    public function destroyQuestion(Request $request, ProfileTestQuestion $question): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $prompt = $question->prompt_en;
        $question->delete();

        AuditLog::record('Removed profile test question', $prompt);

        return back()->with('ok', 'Question removed.');
    }

    /** @return Collection<int, ProfileTestQuestion> */
    private function questions(string $section)
    {
        return ProfileTestQuestion::where('section', $section)
            ->orderBy('position')
            ->with('options')
            ->get();
    }

    private function authorizeAdmin(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }

    /** @return array<string, mixed> */
    private function validateQuestion(Request $request): array
    {
        return $request->validate([
            'section' => ['required', Rule::in(['working_style', 'colour'])],
            'prompt' => ['required', 'string', 'max:500'],
            'options' => ['nullable', 'array'],
            'options.*.label' => ['nullable', 'string', 'max:255'],
            'options.*.animal' => ['nullable', Rule::in(self::ANIMALS)],
        ]);
    }

    /**
     * Replace a question's options. Colour questions are open free-text and
     * never carry options; empty-label rows are ignored.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncOptions(ProfileTestQuestion $question, array $data): void
    {
        if (($data['section'] ?? null) === 'colour') {
            $question->options()->delete();

            return;
        }

        if (! array_key_exists('options', $data) || ! is_array($data['options'])) {
            return;
        }

        $question->options()->delete();

        $position = 0;
        foreach ($data['options'] as $opt) {
            $label = trim((string) ($opt['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $position++;
            $question->options()->create([
                'label_en' => $label,
                'animal' => $opt['animal'] ?? null,
                'position' => $position,
            ]);
        }
    }

    /**
     * Write a compact personality summary onto the employee so the existing
     * Personality card (type / spirit animal / trait bars / blurb) renders the
     * test outcome. No-op when there is nothing meaningful to show yet.
     *
     * @param  array{archetype: ?string, totals: array<string,int>}  $scored
     */
    private function syncPersonality(Employee $employee, array $scored, ?string $mbti): void
    {
        $totals = $scored['totals'];
        $answered = array_sum($totals);

        if ($answered === 0 && ! $mbti) {
            return;
        }

        $meta = ArchetypeCatalog::get($scored['archetype']);

        $traitLabel = [
            'rabbit' => 'Fast & instinctive',
            'tortoise' => 'Steady & reliable',
            'fox' => 'Strategic & adaptable',
            'sloth' => 'Calm & easygoing',
        ];

        $traits = [];
        foreach (ArchetypeScorer::ORDER as $animal) {
            $traits[] = [
                'label' => $traitLabel[$animal],
                'pct' => $answered ? (int) round($totals[$animal] / $answered * 100) : 0,
                'color' => ArchetypeCatalog::get($animal)['swatch'],
            ];
        }
        usort($traits, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        $animalLabel = $scored['archetype'] ? $meta['label'] : null;

        $employee->update([
            'personality' => [
                'type' => $mbti ?: ($animalLabel ? $animalLabel.' archetype' : ''),
                'animal' => $animalLabel,
                'blurb' => trim(($meta['tagline_en'] ?? '').' '.($meta['plays_well_en'] ?? '')),
                'traits' => $traits,
            ],
        ]);
    }
}
