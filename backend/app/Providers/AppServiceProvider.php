<?php

namespace App\Providers;

use App\Listeners\LogSuccessfulLogin;
use App\Listeners\LogSuccessfulLogout;
use Exception;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment() == 'production') {
            $this->app['request']->server->set('HTTPS', true);
        }

        $this->setSchemaDefaultLength();
        $this->registerThemeViewPath();

        // Register activity log event listeners
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Logout::class, LogSuccessfulLogout::class);

        Validator::extend('base64image', function ($attribute, $value, $parameters, $validator) {
            $explode = explode(',', $value);
            $allow = ['png', 'jpg', 'svg', 'jpeg'];
            $format = str_replace(
                [
                    'data:image/',
                    ';',
                    'base64',
                ],
                [
                    '', '', '',
                ],
                $explode[0]
            );

            // check file format
            if (! in_array($format, $allow)) {
                return false;
            }

            // check base64 format
            if (! preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $explode[1])) {
                return false;
            }

            return true;
        });

        $this->bootRoute();
    }

    /**
     * Make theme pages resolvable by Volt.
     *
     * Wave's theme pages use the @volt('name') fragment directive. Volt encodes
     * such a fragment as `volt-anonymous-fragment-<base64 name+path>` and, on
     * render, asks ComponentResolver to turn that alias back into a component
     * class. The resolver only accepts the decoded path if it sits under a
     * mounted Volt directory, `config('view.paths')`, or the compiled view path.
     *
     * Out of the box none of those cover `resources/themes`, so every theme page
     * with a @volt fragment — /profile/{username}, all of /settings,
     * /notifications, /admin/media — dies with "Unable to find component".
     *
     * Adding the themes root to view.paths satisfies the resolver's prefix check
     * without mounting the directory in Volt. That distinction matters: mounting
     * would make Volt's ExtractTemplate precompiler strip the top-level <?php ?>
     * block out of every plain Folio page in the theme (/blog, /changelog),
     * which breaks them instead.
     */
    private function registerThemeViewPath(): void
    {
        $paths = config('view.paths', []);

        $candidates = [
            config('themes.folder', resource_path('themes')),
            // Wave's own package views have the same problem: /admin/media is a
            // @volt fragment living in wave/resources/views.
            base_path('wave/resources/views'),
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && ! in_array($candidate, $paths, true)) {
                $paths[] = $candidate;
            }
        }

        config(['view.paths' => $paths]);
    }

    private function setSchemaDefaultLength(): void
    {
        try {
            Schema::defaultStringLength(191);
        } catch (Exception $exception) {
        }
    }

    public function bootRoute()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: (request()->header('CF-Connecting-IP') ?? request()->ip()));
        });

    }
}
