<?php

namespace Tests\Feature\Setup;

use App\Http\Controllers\TourController;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TourControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create();
        $this->user->markEmailAsVerified();
    }

    public function test_user_can_complete_a_tour(): void
    {
        $this->actingAs($this->user)
            ->postJson('/dashboard/tours/dashboard/complete')
            ->assertOk()
            ->assertJson(['completed' => true]);

        $this->assertContains('dashboard', $this->user->fresh()->completed_tours);
    }

    public function test_completing_a_tour_is_idempotent(): void
    {
        $this->user->update(['completed_tours' => ['pos']]);

        $this->actingAs($this->user)
            ->postJson('/dashboard/tours/pos/complete')
            ->assertOk();

        $this->assertSame(['pos'], $this->user->fresh()->completed_tours);
    }

    public function test_unknown_tour_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson('/dashboard/tours/unknown/complete')
            ->assertNotFound();

        $this->assertNull($this->user->fresh()->completed_tours);
    }

    public function test_all_registered_tours_can_be_completed(): void
    {
        foreach (TourController::TOURS as $tour) {
            $this->actingAs($this->user)
                ->postJson("/dashboard/tours/{$tour}/complete")
                ->assertOk();
        }

        $this->assertSame(TourController::TOURS, $this->user->fresh()->completed_tours);
    }

    public function test_guest_cannot_complete_a_tour(): void
    {
        $this->postJson('/dashboard/tours/dashboard/complete')
            ->assertUnauthorized();
    }

    public function test_user_can_reset_completed_tours(): void
    {
        $this->user->update(['completed_tours' => ['dashboard', 'pos']]);

        $this->actingAs($this->user)
            ->postJson('/dashboard/tours/reset')
            ->assertOk()
            ->assertJson(['completed' => []]);

        $this->assertNull($this->user->fresh()->completed_tours);
    }

    public function test_reset_requires_auth(): void
    {
        $this->postJson('/dashboard/tours/reset')
            ->assertUnauthorized();
    }
}
