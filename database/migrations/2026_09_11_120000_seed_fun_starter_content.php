<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starter content for Unijaya's Wrapped, Side Quest and Plot Twist, copied from the
 * dev database on 2026-09-11 so production does not open those screens empty:
 * the 30 Wrapped character-arc titles, the three live side quests, and the two
 * scheduled Plot Twist polls with their options. Test activity (votes, posts, badges,
 * reactions, built Wrapped stories) is deliberately left out.
 *
 * Unijaya only, found by slug; a database without that tenant (the test suite, a
 * fresh install) is left alone. Idempotent: every row is skipped when an identical
 * one already exists, and arcs only go in for a tenant that has none yet, the same
 * rule WrappedBuild::seedArcsIfNeeded() uses.
 */
return new class extends Migration
{
    private const ARCS = [
        'firefighter' => ['The Firefighter', 'Crisis Whisperer', 'The Extinguisher', 'Emergency Response Unit', 'The One Who Runs Toward It', 'Situation Handler'],
        'helper' => ['The Wingman', 'Team Player of the Month', 'The Assist King/Queen', 'Everyone\'s Backup', 'The Reliable One', 'Behind-the-Scenes MVP'],
        'closer' => ['The Closer', 'Machine Mode: Activated', 'The Finisher', 'Done-and-Dusted Champion', 'The Human Conveyor Belt', 'Unstoppable This Month'],
        'quiet' => ['The Long Game', 'Still Warming Up', 'Building Momentum', 'The Slow Burn', 'Between Chapters', 'The Quiet Month'],
        'steady' => ['The Steady Hand', 'Consistently Consistent', 'The Reliable Regular', 'Middle of the Story', 'Just Getting It Done', 'The Even Keel'],
    ];

    /** [title, suggested_by employee id, created_by employee id] */
    private const QUESTS = [
        ['Share one useful AI prompt', null, 1],
        ['Have lunch with someone outside your project', null, 1],
        ['Recommend one book, show or cafe', 26, null],
    ];

    private const POLLS = [
        [
            'question' => 'Unijaya\'s unofficial national food?', 'kind' => 'fun',
            'opens_on' => '2026-09-14', 'reveals_at' => '2026-09-18 15:00:00',
            'options' => ['Nasi lemak', 'Roti canai', 'Laksa'],
        ],
        [
            'question' => 'What should the next social activity be?', 'kind' => 'social',
            'opens_on' => '2026-09-21', 'reveals_at' => '2026-09-25 15:00:00',
            'options' => ['Bowling', 'Escape room', 'Makan-makan'],
        ],
    ];

    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'unijaya-resources-sdn-bhd')->value('id');
        if ($tenantId === null) {
            return;
        }

        $now = now();
        // The ids are the dev copy's, which is a prod dump; a missing person becomes null.
        $employee = fn (?int $id): ?int => $id !== null
            && DB::table('employees')->where('id', $id)->where('tenant_id', $tenantId)->exists() ? $id : null;

        if (! DB::table('wrapped_arcs')->where('tenant_id', $tenantId)->exists()) {
            foreach (self::ARCS as $rule => $titles) {
                foreach ($titles as $title) {
                    DB::table('wrapped_arcs')->insert([
                        'tenant_id' => $tenantId, 'title' => $title, 'rule' => $rule, 'active' => true,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        foreach (self::QUESTS as [$title, $suggestedBy, $createdBy]) {
            if (DB::table('side_quests')->where('tenant_id', $tenantId)->where('title', $title)->exists()) {
                continue;
            }
            DB::table('side_quests')->insert([
                'tenant_id' => $tenantId, 'title' => $title, 'blurb' => null, 'status' => 'live',
                'suggested_by' => $employee($suggestedBy), 'created_by' => $employee($createdBy),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::POLLS as $poll) {
            if (DB::table('plot_twist_polls')->where('tenant_id', $tenantId)->where('question', $poll['question'])->exists()) {
                continue;
            }
            $pollId = DB::table('plot_twist_polls')->insertGetId([
                'tenant_id' => $tenantId, 'question' => $poll['question'], 'kind' => $poll['kind'],
                'named_employee_id' => null, 'status' => 'open',
                'opens_on' => $poll['opens_on'], 'reveals_at' => $poll['reveals_at'], 'idea_fed_at' => null,
                'created_by' => $employee(1), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($poll['options'] as $order => $label) {
                DB::table('plot_twist_options')->insert([
                    'poll_id' => $pollId, 'label' => $label, 'sort_order' => $order,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data-only migration; by the time anyone rolls back, staff may have voted on
        // or posted to these rows. No-op down.
    }
};
