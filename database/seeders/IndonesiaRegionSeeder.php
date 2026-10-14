<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;
use Laravolt\Indonesia\Seeds\CitiesSeeder;
use Laravolt\Indonesia\Seeds\DistrictsSeeder;
use Laravolt\Indonesia\Seeds\ProvincesSeeder;
use Laravolt\Indonesia\Seeds\VillagesSeeder;

class IndonesiaRegionSeeder extends Seeder
{
    /**
     * Seed the Indonesian region reference data (provinces, cities, districts, villages).
     *
     * The underlying package seeders use insertOrIgnore, so they are safe to re-run
     * and never remove existing data. We skip only when every region table already
     * contains rows to avoid re-parsing the ~80k village rows on every `db:seed`.
     */
    public function run(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if ($this->hasCompleteRegionData()) {
            return;
        }

        $this->call([
            ProvincesSeeder::class,
            CitiesSeeder::class,
            DistrictsSeeder::class,
            VillagesSeeder::class,
        ]);
    }

    /**
     * Whether every region table already has data.
     *
     * Checking all four tables (instead of only provinces) means a partially
     * seeded database — e.g. provinces present but cities missing — is repaired
     * on the next run rather than incorrectly skipped.
     */
    public function hasCompleteRegionData(): bool
    {
        return Province::query()->exists()
            && City::query()->exists()
            && District::query()->exists()
            && Village::query()->exists();
    }
}
