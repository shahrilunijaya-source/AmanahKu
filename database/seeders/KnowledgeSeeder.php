<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\KnowledgeComment;
use App\Models\KnowledgeContribution;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\KnowledgeStar;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class KnowledgeSeeder extends Seeder
{
    /**
     * Seed the Knowledge Bank for the first tenant: the default segment tree, a
     * handful of lessons authored by various staff, and this month's contribution
     * rows. Aisyah (the demo login) is deliberately left "not yet" so the header
     * pulse, the dashboard reminder and the unread badge all have signal on first
     * sign-in. Idempotent: skips entirely once segments exist for the tenant.
     */
    public function run(): void
    {
        $tenant = Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }
        if (KnowledgeSegment::where('tenant_id', $tenant->id)->exists()) {
            return;
        }
        $employees = Employee::where('tenant_id', $tenant->id)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        $by = fn (string $name) => $employees->first(fn ($e) => str_starts_with($e->name, $name));
        $firstId = $employees->first()->id;

        // ── Default segment tree (top level → sub-segments) ──────────────────
        $tree = [
            'Lessons Learned' => ['Process & Workflow', 'Systems & Tech', 'People & HR'],
            'Best Practices' => ['Operations', 'Client Management'],
            'Pitfalls & Fixes' => [],
            'SOPs & How-to' => [],
            'Client & Vendor' => [],
        ];

        $segId = [];      // label → id (top level)
        $subId = [];      // "Parent / Child" → id
        $order = 0;
        foreach ($tree as $label => $children) {
            $parent = KnowledgeSegment::create([
                'tenant_id' => $tenant->id,
                'label' => $label,
                'created_by' => $firstId,
                'sort_order' => $order++,
            ]);
            $segId[$label] = $parent->id;

            $childOrder = 0;
            foreach ($children as $childLabel) {
                $child = KnowledgeSegment::create([
                    'tenant_id' => $tenant->id,
                    'label' => $childLabel,
                    'parent_id' => $parent->id,
                    'created_by' => $firstId,
                    'sort_order' => $childOrder++,
                ]);
                $subId["$label / $childLabel"] = $child->id;
            }
        }

        // ── Sample lessons. [seg, sub|null, author, title, body, tags, helpful, daysAgo] ──
        $entries = [
            ['Lessons Learned', 'Systems & Tech', 'Ravi Kumar', 'Always re-run migrations against a seeded DB before a release',
                "We shipped a migration that passed on an empty schema but broke on production data because of an existing null column. Now I always `migrate:fresh --seed` locally and run the new migration on a copy of seeded data first. Caught two more issues since.",
                ['laravel', 'migrations', 'release'], 9, 4],
            ['Lessons Learned', 'Process & Workflow', 'Nurul Iman', 'Confirm scope in writing before starting onboarding paperwork',
                "A new hire's start date moved twice and we'd already prepared the full pack. A one-line email confirming start date + position before generating documents saves a full afternoon of rework.",
                ['onboarding', 'hr'], 6, 7],
            ['Pitfalls & Fixes', null, 'Faizal Othman', 'Site fuel claims need the odometer photo at both ends',
                "Finance bounced three Seremban claims because only the end reading was attached. Snap the odometer at departure AND arrival — the claim clears same day instead of a week of back-and-forth.",
                ['claims', 'operations'], 5, 10],
            ['Best Practices', 'Client Management', 'Daniel Lee', 'Send a one-paragraph recap within an hour of every client call',
                "Closing RM 1.2M this quarter came down to recaps. A short 'here's what we agreed' email right after the call removes ambiguity and gives the client something to forward internally. It also doubles as your paper trail.",
                ['sales', 'client'], 11, 12],
            ['Best Practices', 'Operations', 'Lim Chee Keong', 'Batch purchase orders on Tuesdays to hit vendor cut-offs',
                "Two of our main vendors lock weekly orders by Wednesday noon. Submitting on Tuesday consistently gets us same-week delivery instead of slipping a week. Small change, big lead-time win.",
                ['procurement'], 4, 14],
            ['Lessons Learned', 'People & HR', 'Nurul Iman', 'Document the playbook the moment a process improves',
                "We cut onboarding from 14 to 9 days but nearly lost the gains during a handover because it lived in my head. Writing the playbook the same week locks the improvement in for whoever runs it next.",
                ['process', 'knowledge'], 8, 18],
            ['SOPs & How-to', null, 'Tan Wei Ming', 'How to reconcile the monthly close in under a day',
                "Automating the bank-statement match dropped our close from 3 days to under 1. The trick: import the statement CSV first, auto-match by reference, then only eyeball the unmatched rows. Template is in the Finance shared drive.",
                ['finance', 'automation'], 7, 21],
            ['Client & Vendor', null, 'Ravi Kumar', 'Keep a P1 contact sheet pinned for every vendor',
                "Resolved a P1 outage in under 30 minutes only because the vendor's after-hours escalation number was on a pinned sheet. Don't hunt for it mid-incident — collect them now.",
                ['it', 'incident'], 6, 24],
        ];

        $entryByTitle = [];
        foreach ($entries as $row) {
            [$seg, $sub, $authorName, $title, $body, $tags, $stars, $daysAgo] = $row;
            $author = $by($authorName);
            if (! $author) {
                continue;
            }

            $entry = KnowledgeEntry::create([
                'tenant_id' => $tenant->id,
                'seg_id' => $segId[$seg],
                'subseg_id' => $sub ? ($subId["$seg / $sub"] ?? null) : null,
                'employee_id' => $author->id,
                'title' => $title,
                'body' => $body,
                'tags' => $tags,
            ]);
            $when = Carbon::now()->subDays($daysAgo);
            $entry->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
            $entryByTitle[$title] = $entry;

            // Stars from the first N distinct employees (respects the unique key).
            foreach ($employees->take($stars) as $starrer) {
                KnowledgeStar::create([
                    'tenant_id' => $tenant->id,
                    'entry_id' => $entry->id,
                    'employee_id' => $starrer->id,
                ]);
            }
        }

        // A little discussion on a couple of entries. [entryTitle, author, body]
        $comments = [
            ['Always re-run migrations against a seeded DB before a release', 'Tan Wei Ming', 'Saved me last sprint — adding this to our release checklist.'],
            ['Always re-run migrations against a seeded DB before a release', 'Nurul Iman', 'Could we link the staging refresh SOP here too? https://wiki.unijaya.local/sops/staging-refresh'],
            ['Send a one-paragraph recap within an hour of every client call', 'Faizal Othman', 'Works for internal stakeholder calls as well, not just clients.'],
        ];
        foreach ($comments as [$title, $authorName, $body]) {
            $entry = $entryByTitle[$title] ?? null;
            $author = $by($authorName);
            if (! $entry || ! $author) {
                continue;
            }
            KnowledgeComment::create([
                'tenant_id' => $tenant->id,
                'entry_id' => $entry->id,
                'employee_id' => $author->id,
                'body' => $body,
            ]);
        }

        // ── This month's contributions. Everyone who authored has contributed;
        // Aisyah, Hafiz and Farah are left "not yet" for a realistic compliance mix
        // (and to demo the reminder/pulse for the Aisyah login). ──
        $submitted = ['Ravi Kumar', 'Nurul Iman', 'Faizal Othman', 'Daniel Lee', 'Lim Chee Keong', 'Tan Wei Ming'];
        foreach ($submitted as $name) {
            $emp = $by($name);
            if (! $emp) {
                continue;
            }
            KnowledgeContribution::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'year' => (int) now()->year,
                'month' => (int) now()->month,
                'submitted' => true,
            ]);
        }

        // A prior-month contribution from each so the "team streak" reads 2 months.
        foreach ($submitted as $name) {
            $emp = $by($name);
            if (! $emp) {
                continue;
            }
            $last = now()->subMonthNoOverflow();
            KnowledgeContribution::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $emp->id,
                'year' => (int) $last->year,
                'month' => (int) $last->month,
                'submitted' => true,
            ]);
        }
    }
}
