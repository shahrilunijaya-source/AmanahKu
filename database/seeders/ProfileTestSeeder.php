<?php

namespace Database\Seeders;

use App\Models\ProfileTestQuestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the global Profile Test instrument (working-style + colour questions).
 * Ported from the Hiring platform. Guarded so it never overwrites questions an
 * HR admin has edited — wipe the tables manually to re-seed.
 */
class ProfileTestSeeder extends Seeder
{
    public function run(): void
    {
        if (ProfileTestQuestion::exists()) {
            return;
        }

        DB::transaction(function () {
            // Working-style questions. Each has 4 options, one per animal,
            // phrased specifically for that question. Animal order is fixed:
            // rabbit, tortoise, fox, sloth.
            $working = [
                [
                    'prompt' => 'Approach to new tasks or challenges',
                    'opts' => [
                        ['rabbit',   'Jump straight in and figure it out as I go'],
                        ['tortoise', 'Plan carefully, then start step by step'],
                        ['fox',      'Size up the situation and find the smartest angle'],
                        ['sloth',    'Ease into it, no rush'],
                    ],
                ],
                [
                    'prompt' => 'Behaviour in group settings',
                    'opts' => [
                        ['rabbit',   'Energise the room and get things moving'],
                        ['tortoise', 'Quietly keep the group steady and on track'],
                        ['fox',      'Read the dynamics and steer from the side'],
                        ['sloth',    'Go with the flow, let others lead'],
                    ],
                ],
                [
                    'prompt' => 'Handling unexpected change',
                    'opts' => [
                        ['rabbit',   'React fast and adapt on instinct'],
                        ['tortoise', 'Stay calm and stick to what works'],
                        ['fox',      'Rethink the plan and find a new route'],
                        ['sloth',    'Take it in stride, no panic'],
                    ],
                ],
                [
                    'prompt' => 'Preferred role on a team project',
                    'opts' => [
                        ['rabbit',   'The starter who kicks off momentum'],
                        ['tortoise', 'The reliable backbone who finishes work'],
                        ['fox',      'The strategist who plans the approach'],
                        ['sloth',    'The easygoing glue who keeps the peace'],
                    ],
                ],
                [
                    'prompt' => 'Decision-making style',
                    'opts' => [
                        ['rabbit',   'Decide quickly on gut feel'],
                        ['tortoise', 'Weigh it slowly and choose the safe option'],
                        ['fox',      'Analyse the options and pick the cleverest'],
                        ['sloth',    'Let it settle, decide when ready'],
                    ],
                ],
                [
                    'prompt' => 'Reaction to a setback or failure',
                    'opts' => [
                        ['rabbit',   'Bounce back fast and try again'],
                        ['tortoise', 'Steady myself and rebuild carefully'],
                        ['fox',      'Learn the lesson and change tactics'],
                        ['sloth',    'Shrug it off and move on calmly'],
                    ],
                ],
                [
                    'prompt' => 'Communication preference',
                    'opts' => [
                        ['rabbit',   'Fast, direct, lots of energy'],
                        ['tortoise', 'Clear, consistent, dependable'],
                        ['fox',      'Tailored — adapt to my audience'],
                        ['sloth',    'Relaxed, easygoing, low-key'],
                    ],
                ],
                [
                    'prompt' => 'What motivates you at work',
                    'opts' => [
                        ['rabbit',   'Speed, action, quick wins'],
                        ['tortoise', 'Stability and steady progress'],
                        ['fox',      'Solving tricky problems cleverly'],
                        ['sloth',    'A calm, low-pressure environment'],
                    ],
                ],
                [
                    'prompt' => 'How you spend free time',
                    'opts' => [
                        ['rabbit',   'Always on the go — activities, sports'],
                        ['tortoise', 'Routine hobbies I can rely on'],
                        ['fox',      'Exploring new ideas and skills'],
                        ['sloth',    'Resting and relaxing, taking it slow'],
                    ],
                ],
                [
                    'prompt' => 'Approach to goal-setting',
                    'opts' => [
                        ['rabbit',   'Aim high and chase it immediately'],
                        ['tortoise', 'Set realistic goals and grind steadily'],
                        ['fox',      'Set smart goals with a clear strategy'],
                        ['sloth',    'Keep goals loose and flexible'],
                    ],
                ],
            ];

            foreach ($working as $i => $item) {
                $q = ProfileTestQuestion::create([
                    'section' => 'working_style',
                    'prompt_en' => $item['prompt'],
                    'position' => $i + 1,
                ]);
                foreach ($item['opts'] as $p => [$animal, $label]) {
                    $q->options()->create([
                        'label_en' => $label,
                        'animal' => $animal,
                        'position' => $p + 1,
                    ]);
                }
            }

            // Colour / rapport — free-text icebreakers, no options.
            $colour = [
                'Your superpower',
                'Movie-marathon genre',
                'Dream vacation destination',
                'Preferred transport',
                'Favourite Malaysian landmark',
            ];
            foreach ($colour as $i => $prompt) {
                ProfileTestQuestion::create([
                    'section' => 'colour',
                    'prompt_en' => $prompt,
                    'position' => $i + 1,
                ]);
            }
        });
    }
}
