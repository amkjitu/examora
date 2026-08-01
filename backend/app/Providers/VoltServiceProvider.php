<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

class VoltServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        /*
         * Theme pages are deliberately NOT mounted here.
         *
         * Volt's ExtractTemplate precompiler strips the top-level <?php ?> block
         * from every Blade file inside a mounted directory, so mounting
         * resources/themes/<theme>/pages would break every plain Folio page in
         * the theme (/blog, /changelog) whose data is prepared in that block.
         *
         * Theme pages use the @volt('name') *fragment* directive instead, and
         * Volt's ComponentResolver resolves those aliases against
         * config('view.paths') as well as mounted directories — which is why
         * AppServiceProvider adds the themes root to view.paths. See the note
         * there.
         */
        Volt::mount([
            config('livewire.view_path', resource_path('views/livewire')),
            resource_path('views/pages'),
        ]);
    }
}
