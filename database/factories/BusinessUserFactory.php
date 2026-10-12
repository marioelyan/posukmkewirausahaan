<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessUser>
 */
class BusinessUserFactory extends Factory
{
    protected $model = BusinessUser::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'role' => BusinessUser::ROLE_STAFF,
            'status' => BusinessUser::STATUS_ACTIVE,
            'default_outlet_id' => null,
            'invited_by_user_id' => null,
            'invited_at' => null,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => BusinessUser::ROLE_OWNER,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => BusinessUser::ROLE_MANAGER,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BusinessUser::STATUS_SUSPENDED,
        ]);
    }
}
