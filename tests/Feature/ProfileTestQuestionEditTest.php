<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProfileTestQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProfileCompletion;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The question bank is global and edited in place while people are already
 * holding answers to it. These cover what an HR edit must NOT destroy.
 * Harness (setUp / hrActor) copied from CaseTest.
 */
class ProfileTestQuestionEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    /** A working-style question with two animal-tagged options. */
    private function question(string $prompt = 'How do you start a task?'): ProfileTestQuestion
    {
        $q = ProfileTestQuestion::create(['section' => 'working_style', 'prompt_en' => $prompt, 'position' => 1]);
        $q->options()->create(['label_en' => 'Straight in', 'animal' => 'rabbit', 'position' => 1]);
        $q->options()->create(['label_en' => 'Plan first', 'animal' => 'tortoise', 'position' => 2]);

        return $q->fresh('options');
    }

    /** @param array<int, array<string, mixed>> $options */
    private function saveQuestion(ProfileTestQuestion $q, string $prompt, array $options): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/profile-test/questions/'.$q->id, [
                'section' => 'working_style',
                'prompt' => $prompt,
                'options' => $options,
            ])->assertRedirect();
    }

    public function test_editing_a_prompt_keeps_the_option_ids_answers_point_at(): void
    {
        $q = $this->question();
        $rabbit = $q->options->firstWhere('animal', 'rabbit');
        $tortoise = $q->options->firstWhere('animal', 'tortoise');

        $this->employee->profileTestResult()->create([
            'working_style_answers' => [$q->id => $rabbit->id],
            'animal_archetype' => 'rabbit',
            'submitted_at' => now(),
        ]);

        // HR fixes a typo in the prompt and sends the options back untouched.
        $this->saveQuestion($q, 'How do you begin a task?', [
            ['id' => $rabbit->id, 'label' => 'Straight in', 'animal' => 'rabbit'],
            ['id' => $tortoise->id, 'label' => 'Plan first', 'animal' => 'tortoise'],
        ]);

        $this->assertSame('How do you begin a task?', $q->fresh()->prompt_en);
        // Same rows, same ids — the saved answer still resolves.
        $this->assertEqualsCanonicalizing(
            [$rabbit->id, $tortoise->id],
            $q->options()->pluck('id')->all(),
        );
        $this->assertTrue(app(ProfileCompletion::class)->personalityDone($this->employee->fresh()));
    }

    public function test_relabelling_an_option_updates_the_row_instead_of_replacing_it(): void
    {
        $q = $this->question();
        $rabbit = $q->options->firstWhere('animal', 'rabbit');
        $tortoise = $q->options->firstWhere('animal', 'tortoise');

        $this->saveQuestion($q, $q->prompt_en, [
            ['id' => $rabbit->id, 'label' => 'Dive straight in', 'animal' => 'fox'],
            ['id' => $tortoise->id, 'label' => 'Plan first', 'animal' => 'tortoise'],
        ]);

        $rabbit->refresh();
        $this->assertSame('Dive straight in', $rabbit->label_en);
        $this->assertSame('fox', $rabbit->animal);
        $this->assertSame(2, $q->options()->count());
    }

    public function test_a_removed_option_row_is_deleted(): void
    {
        $q = $this->question();
        $rabbit = $q->options->firstWhere('animal', 'rabbit');
        $tortoise = $q->options->firstWhere('animal', 'tortoise');

        // HR clears the second row's label — an empty label means "drop this option".
        $this->saveQuestion($q, $q->prompt_en, [
            ['id' => $rabbit->id, 'label' => 'Straight in', 'animal' => 'rabbit'],
            ['id' => $tortoise->id, 'label' => '', 'animal' => 'tortoise'],
        ]);

        $this->assertSame([$rabbit->id], $q->options()->pluck('id')->all());
    }

    public function test_an_option_id_from_another_question_is_not_adopted(): void
    {
        $mine = $this->question();
        $other = $this->question('Someone else question');
        $stolen = $other->options->first();

        $this->saveQuestion($mine, $mine->prompt_en, [
            ['id' => $stolen->id, 'label' => 'Hijacked', 'animal' => 'fox'],
        ]);

        // The foreign row stays on its own question, untouched.
        $stolen->refresh();
        $this->assertSame($other->id, $stolen->profile_test_question_id);
        $this->assertSame('Straight in', $stolen->label_en);
        // The submitted row became a NEW option on the edited question.
        $this->assertSame(1, $mine->options()->count());
        $this->assertSame('Hijacked', $mine->options()->first()->label_en);
    }

    public function test_an_answer_to_a_deleted_question_does_not_stand_in_for_a_new_one(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $old = $this->question('Retired question');
        $this->employee->profileTestResult()->create([
            'working_style_answers' => [$old->id => $old->options->first()->id],
            'submitted_at' => now(),
        ]);
        $this->assertTrue(app(ProfileCompletion::class)->personalityDone($this->employee->fresh()));

        // HR retires that question and asks a different one. The answer count is still
        // 1 of 1, but it answers nothing that is live.
        $old->delete();
        $this->question('Brand new question');

        $this->assertFalse(app(ProfileCompletion::class)->personalityDone($this->employee->fresh()));
    }
}
