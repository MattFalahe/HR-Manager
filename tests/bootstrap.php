<?php

// The tests run without SeAT and without a database. Laravel's query builder,
// SeAT's models and the facades HR reads through are stood in for, so the code
// under test is the real code and only the data source is fake.

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    foreach (['HrManager\\Tests\\' => __DIR__ . '/', 'HrManager\\' => dirname(__DIR__) . '/src/'] as $prefix => $root) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

require __DIR__ . '/Fake/helpers.php';

// Declared before anything can autoload the real ones. class_exists with
// autoloading off, so a name that merely could be loaded is still ours.
$standIns = [
    'Illuminate\Support\Facades\Log'                 => HrManager\Tests\NoLog::class,
    'Illuminate\Support\Facades\DB'                  => HrManager\Tests\Fake\Db::class,
    'Illuminate\Support\Facades\Schema'              => HrManager\Tests\Fake\Schema::class,
    'Illuminate\Support\Facades\Cache'               => HrManager\Tests\Fake\Cache::class,
    'Illuminate\Support\Str'                         => HrManager\Tests\Fake\Str::class,
    'Illuminate\Database\Eloquent\Collection'        => HrManager\Tests\Fake\EloquentCollection::class,
    'Seat\Eveapi\Models\Character\CharacterInfo'     => HrManager\Tests\Fake\CharacterInfo::class,
    'Seat\Eveapi\Models\Character\CharacterAffiliation' => HrManager\Tests\Fake\CharacterAffiliation::class,
    'Seat\Eveapi\Models\RefreshToken'                => HrManager\Tests\Fake\RefreshToken::class,
    'Seat\Web\Models\User'                           => HrManager\Tests\Fake\User::class,

    // HR asks whether a sibling plugin is installed by looking for one of its
    // classes, so this is what makes Mining Manager present for the tests.
    'MiningManager\Models\MiningLedger'              => HrManager\Tests\Fake\MiningLedger::class,
];

foreach ($standIns as $real => $fake) {
    if (!class_exists($real, false)) {
        class_alias($fake, $real);
    }
}
