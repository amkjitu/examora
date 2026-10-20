<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the database after migrations for tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Wave's pages reference Vite assets. The manifest only exists after
        // `npm run build`, which CI does not run for the backend, so render
        // plain tags instead of failing on a missing public/build.
        $this->withoutVite();

        if (Schema::hasTable('roles') &&
            ! Role::where('name', 'registered')->exists()) {
            Artisan::call('db:seed', ['--class' => 'RolesTableSeeder']);
        }
    }
}
