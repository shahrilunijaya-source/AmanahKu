<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScreenGuideTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hrEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->hrUser = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->hrEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->hrUser->id,
            'name' => 'HR Manager',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function actAsHr(): self
    {
        return $this->actingAs($this->hrUser)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'hr',
        ]);
    }

    public function test_the_guide_modal_is_marked_up_as_a_dialog(): void
    {
        $response = $this->actAsHr()->get('/app/attendance-report');

        $response->assertOk();
        $response->assertSee('role="dialog"', false);
        $response->assertSee('aria-modal="true"', false);
    }

    public function test_the_guide_carries_no_inline_styles(): void
    {
        $renderedPartial = view('partials.guide', [
            'key' => 'attendance-report',
            'en' => [
                'title' => 'Attendance Reports',
                'body' => 'Test body',
                'who' => 'HR',
                'steps' => ['Step 1'],
            ],
        ])->render();

        $this->assertStringNotContainsString('style="', $renderedPartial);

        $response = $this->actAsHr()->get('/app/attendance-report');
        $content = $response->getContent();
        $startPos = strpos($content, 'class="uj-guide-host"');
        $this->assertNotFalse($startPos, 'Guide host class must exist on page');
        $endPos = strpos($content, '</template>', $startPos) + strlen('</template>');
        $guideSlice = substr($content, $startPos, $endPos - $startPos);

        $this->assertStringNotContainsString('style="', $guideSlice);
    }

    public function test_the_guide_still_renders_the_screen_copy(): void
    {
        $response = $this->actAsHr()->get('/app/attendance-report');

        $response->assertOk();
        $response->assertSee('One row for every active employee on every working day');
    }

    public function test_the_guide_is_skipped_in_embed_mode(): void
    {
        $normalResponse = $this->actAsHr()->get('/app/attendance-report');
        $normalResponse->assertOk();
        $normalResponse->assertSee('What is this screen for?');

        $embedResponse = $this->actAsHr()->get('/app/attendance-report?embed=1');
        $embedResponse->assertOk();
        $embedResponse->assertDontSee('What is this screen for?');
        $embedResponse->assertSee('Attendance Reports');
    }
}
