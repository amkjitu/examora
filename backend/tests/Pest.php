<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

/*
 * Isolation strategy: DatabaseTransactions, not RefreshDatabase.
 *
 * tests/bootstrap.php builds a migrated and seeded SQLite file before PHPUnit
 * starts, because Wave resolves its theme, settings and Folio page mounts from
 * the database while booting — earlier than RefreshDatabase would migrate.
 * Wrapping each test in a transaction gives isolation without ever leaving the
 * database empty at boot time.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

/*
 * Examora's domain tests (Feature/Domain) live under Feature rather than Unit on
 * purpose: they exercise the scheduler, scorer and gateway against Eloquent, so
 * they boot the framework. tests/Unit is kept framework-free because Wave's
 * PluginAutoloaderTest asserts on the global autoloader stack and fails if
 * anything has booted the application earlier in the same process.
 */

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
