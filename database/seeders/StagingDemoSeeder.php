<?php

namespace Database\Seeders;

use App\Services\StagingDemo;
use Illuminate\Database\Seeder;

class StagingDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(StagingDemo::class)->seed();
    }
}
