<?php

namespace HrManager\Tests\Structure;

use HrManager\Tests\TestCase;

/**
 * Things a mistake in the source would break on a live install that nothing
 * else catches before then, read from the files themselves.
 */
class SourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const SRC = self::ROOT . 'src/';

    /** @return string[] paths of the files under src/ with this ending */
    private static function files(string $ending, string $under = ''): array
    {
        $directory = self::SRC . $under;
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, $ending)) {
                $found[] = $path;
            }
        }
        sort($found);

        return $found;
    }

    /** @return array<string, array<int, string>> command signature => its options */
    private static function commands(): array
    {
        $known = [];
        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            if (preg_match("/signature\s*=\s*'hr-manager:([a-z-]+)(.*?)';/s", file_get_contents($path), $signature)) {
                preg_match_all('/\{--([a-z-]+)/', $signature[2], $options);
                $known[$signature[1]] = $options[1];
            }
        }

        return $known;
    }

    /** The array literal whose opening bracket is at $open, brackets included. */
    private static function literalAt(string $code, int $open): string
    {
        $depth = 0;
        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '[') {
                $depth++;
            } elseif ($code[$i] === ']' && --$depth === 0) {
                break;
            }
        }

        return substr($code, $open, $i - $open + 1);
    }

    /**
     * Keys of an array literal and of the arrays nested one level in.
     *
     * @return array<string, string[]> top-level key => its own keys
     */
    private static function literalKeys(string $literal): array
    {
        $keys = [];
        $parent = null;
        preg_match_all("/'(\\w+)'\\s*=>/", $literal, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[1] as [$key, $at]) {
            $before = substr($literal, 0, $at);
            $depth = substr_count($before, '[') - substr_count($before, ']');
            if ($depth === 1) {
                $parent = $key;
                $keys[$key] = [];
            } elseif ($depth === 2 && $parent !== null) {
                $keys[$parent][] = $key;
            }
        }

        return $keys;
    }

    /** A method's code with its closures cut out, so their returns are not its own. */
    private static function withoutClosures(string $code): string
    {
        while (($at = strpos($code, 'function (')) !== false) {
            $depth = 0;
            for ($i = strpos($code, '{', $at), $n = strlen($code); $i < $n; $i++) {
                if ($code[$i] === '{') {
                    $depth++;
                } elseif ($code[$i] === '}' && --$depth === 0) {
                    break;
                }
            }
            $code = substr($code, 0, $at) . substr($code, $i + 1);
        }

        return $code;
    }

    /**
     * Blade reads a directive with \B@\w+, so counting @if and @endif proves
     * nothing: {{ $h }}h@endif is not a directive, and leaves its @if open.
     * php -l on a .blade.php file says nothing at all.
     */
    public function test_every_blade_directive_is_closed_the_way_blade_reads_it(): void
    {
        $opens = [
            'if' => 'endif', 'unless' => 'endunless', 'isset' => 'endisset', 'foreach' => 'endforeach',
            'forelse' => 'endforelse', 'for' => 'endfor', 'while' => 'endwhile', 'push' => 'endpush',
        ];
        $closes = array_flip($opens) + ['endsection' => 'section', 'stop' => 'section', 'endphp' => 'php'];

        foreach (self::files('.blade.php') as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
            $source = preg_replace('/\B@php\b(?!\s*\().*?\B@endphp\b/s', '', $source);
            // A section with its content in the call has nothing to close.
            $source = preg_replace("/\B@section\(\s*'[^']*'\s*,.*\)\s*$/m", '', $source);

            preg_match_all('/\B@(\w+)/', $source, $matches);
            $stack = [];
            foreach ($matches[1] as $directive) {
                if ($directive === 'section') {
                    $stack[] = 'section';
                } elseif (isset($opens[$directive])) {
                    $stack[] = $directive;
                } elseif (isset($closes[$directive])) {
                    $this->assertSame($closes[$directive], array_pop($stack), basename($path) . ": @$directive closes the wrong block");
                }
            }

            $this->assertSame([], $stack, basename($path) . ': left open');
        }
    }

    public function test_every_language_key_a_literal_call_names_exists(): void
    {
        $lang = [];
        foreach (self::files('.php', 'Resources/lang/en') as $path) {
            $lang[basename($path, '.php')] = require $path;
        }

        foreach (array_merge(self::files('.php'), self::files('.blade.php')) as $path) {
            preg_match_all("/\b(?:trans|trans_choice|__)\(\s*'hr-manager::([a-z-]+)\.([a-zA-Z0-9_.]+)'\s*[,)]/", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $file, $key]) {
                $value = $lang[$file] ?? null;
                foreach (explode('.', $key) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                $this->assertNotNull($value, basename($path) . " names $file.$key, which is not there");
            }
        }
    }

    /**
     * Laravel resolves a migration by name and fatals on an anonymous one, and
     * the class has to match the filename exactly.
     */
    public function test_migrations_are_named_classes_matching_their_file(): void
    {
        $paths = self::files('.php', 'Database/migrations');
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
            $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix)));

            $this->assertStringNotContainsString('return new class', $source, basename($path));
            $this->assertMatchesRegularExpression('/\bclass ' . $class . '\b/', $source, basename($path));
        }
    }

    /**
     * A repeated date and number is two migrations racing for one slot, and
     * which one runs is up to the filesystem.
     */
    public function test_no_two_migrations_share_a_date_and_number(): void
    {
        $seen = [];
        foreach (self::files('.php', 'Database/migrations') as $path) {
            $prefix = substr(basename($path), 0, 17);
            $this->assertArrayNotHasKey($prefix, $seen, "$prefix is used twice");
            $seen[$prefix] = basename($path);
        }
    }

    /** MySQL stops at 64 characters, so a long composite index has to be named by hand. */
    public function test_index_names_fit_in_what_mysql_allows(): void
    {
        foreach (self::files('.php', 'Database/migrations') as $path) {
            preg_match_all("/'((?:hr|idx)_[a-z_0-9]+)'/", file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                $this->assertLessThanOrEqual(64, strlen($name), $name . ' in ' . basename($path));
            }
        }
    }

    /**
     * SeAT's tables are read, never written. HR's data lives in HR's tables.
     *
     * The exceptions are SeAT's own interfaces for things it expects a plugin
     * to drive, and each is deliberate: the schedule table is how a plugin
     * registers its cron, the ACL tables are how the applicant access feature
     * grants and withdraws a recruit's scoped access, and universe_names is
     * SeAT's resolved-name cache, which HR fills in for names SeAT has not
     * seen. Anything else on this list is a bug.
     */
    public function test_nothing_writes_a_table_that_is_not_the_plugins(): void
    {
        $allowed = ['schedules', 'roles', 'permissions', 'permission_role', 'role_user', 'universe_names'];

        foreach (self::files('.php') as $path) {
            preg_match_all("/DB::table\('([a-zA-Z_]+)(?: as \w+)?'\)((?:(?!DB::table|;).)*?)->(insert|insertOrIgnore|insertUsing|insertGetId|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\(/s", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $table, , $verb]) {
                if (in_array($table, $allowed, true)) {
                    continue;
                }
                $this->assertStringStartsWith('hr_manager_', $table, basename($path) . " runs $verb on $table");
            }
        }
    }

    /** SeAT's schema is SeAT's, and ours only ever changes in a migration. */
    public function test_no_schema_is_changed_outside_a_migration(): void
    {
        foreach (self::files('.php') as $path) {
            if (str_contains($path, 'Database/migrations')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/Schema::(create|table|drop|dropIfExists|rename)\(/',
                file_get_contents($path),
                basename($path) . ' changes a schema outside a migration'
            );
        }
    }

    public function test_every_command_is_registered(): void
    {
        $provider = file_get_contents(self::SRC . 'HrManagerServiceProvider.php');

        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            $class = basename($path, '.php');
            $this->assertStringContainsString("$class::class", $provider, "$class is never registered, so nothing can run it");
        }
    }

    /**
     * Symfony's Command declares these public, and narrowing one is a fatal at
     * class-load, not at call. php -l cannot see it, and it takes the whole
     * install down on composer's autoload dump rather than when the command is
     * run: a private run() once broke every container on boot.
     */
    public function test_no_command_narrows_a_method_symfony_declares_public(): void
    {
        $taken = [
            'run', 'execute', 'initialize', 'interact', 'configure', 'handle',
            'getName', 'setName', 'getHelp', 'setHelp', 'getDescription', 'setDescription',
            'isEnabled', 'getDefinition', 'setDefinition', 'getApplication', 'setApplication',
            'addOption', 'addArgument', 'getSynopsis', 'mergeApplicationDefinition',
            'ask', 'confirm', 'choice', 'secret', 'call', 'callSilent',
            'option', 'options', 'argument', 'arguments', 'line', 'info', 'warn', 'error', 'comment', 'table',
        ];

        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            preg_match_all('/^\s*(private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(/m', file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $visibility, $method]) {
                $this->assertNotContains(
                    $method,
                    $taken,
                    basename($path) . ": $visibility function $method() narrows a method Symfony declares public"
                );
            }
        }
    }

    /**
     * The Diagnostic page warns when a scheduled command is missing, off a list
     * it keeps by hand. A command scheduled but absent from that list is never
     * checked, so it can fall off the schedule unnoticed, which is the opposite
     * of what the page is for.
     */
    public function test_the_schedule_and_the_health_check_list_the_same_commands(): void
    {
        preg_match_all(
            "/'command'\s*=>\s*'([^']+)'/",
            file_get_contents(self::SRC . 'Database/Seeders/ScheduleSeeder.php'),
            $scheduled
        );

        $this->assertNotEmpty($scheduled[1]);

        preg_match('/HR_SCHEDULE = \[(.*?)\];/s', file_get_contents(self::SRC . 'Http/Controllers/DiagnosticController.php'), $block);
        preg_match_all("/'([^']+)'/", $block[1] ?? '', $declared);

        $this->assertEqualsCanonicalizing(
            array_values(array_unique($scheduled[1])),
            array_values(array_unique($declared[1]))
        );
    }

    public function test_every_command_has_a_signature_that_can_be_read(): void
    {
        $paths = glob(self::SRC . 'Console/Commands/*Command.php');
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertSame(
                1,
                preg_match("/signature\s*=\s*'hr-manager:([a-z-]+)(.*?)';/s", file_get_contents($path)),
                basename($path) . ' has no readable hr-manager: signature'
            );
        }
    }

    /**
     * A command nobody documents is a command nobody runs, and a documented
     * command that no longer exists is an operator typing something that
     * errors.
     */
    public function test_the_docs_cover_every_command_and_name_none_that_is_gone(): void
    {
        $known = self::commands();
        $this->assertNotEmpty($known);

        foreach (['README.md', 'src/Resources/views/help/index.blade.php'] as $file) {
            $docs = file_get_contents(self::ROOT . $file);

            foreach (array_keys($known) as $command) {
                $this->assertStringContainsString("hr-manager:$command", $docs, "$file leaves out $command");
            }

            preg_match_all('/hr-manager:([a-z-]+)/', $docs, $named);
            foreach (array_unique($named[1]) as $command) {
                $this->assertArrayHasKey($command, $known, "$file names $command, which is not a command");
            }
        }
    }

    /** A package name with a branch in it stops working the day the branch goes, and people copy what the docs say. */
    public function test_the_docs_name_the_package_with_no_branch(): void
    {
        foreach (['README.md', 'CHANGELOG.md'] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/hr-manager:dev\b|dev-dev-|composer require \S*:dev/',
                file_get_contents(self::ROOT . $file),
                $file
            );
        }
    }

    /**
     * The nightly pass must not go back to reading the profile page. That call
     * is what made it take over an hour on a big install: the classifier wants
     * three fields and getPlayerSummary builds dozens, including a per-alt
     * cross-plugin rollup and a corp-history walk it then throws away.
     */
    public function test_the_classifier_reads_the_lean_signals_and_not_the_profile(): void
    {
        $classifier = file_get_contents(self::SRC . 'Services/ClassifierService.php');

        $this->assertStringContainsString('getClassifierSignals(', $classifier);
        $this->assertStringNotContainsString('getPlayerSummary(', $classifier);
    }

    /**
     * And it must prime the roster before classifying it. Without this the
     * answers are still right, just paid for per player, which is the cost the
     * batched read exists to remove.
     */
    public function test_the_classifier_primes_the_roster_before_walking_it(): void
    {
        $classifier = file_get_contents(self::SRC . 'Services/ClassifierService.php');

        $this->assertStringContainsString('primeActivityCache(', $classifier);

        $primeAt = strpos($classifier, 'primeActivitySignals($userIds');
        $loopAt = strpos($classifier, 'foreach ($userIds as $userId)');

        $this->assertNotFalse($primeAt, 'classifyCorporation no longer primes');
        $this->assertNotFalse($loopAt);
        $this->assertLessThan($loopAt, $primeAt, 'the prime has to happen before the pass, or it buys nothing');
    }

    /**
     * No cross-plugin call belongs in the per-character activity read. One
     * bridge call per character is exactly the shape that cost an hour a night,
     * and the same trap was already documented and worked around in
     * CharacterRoleClassifier before it bit here.
     */
    public function test_the_activity_read_makes_no_cross_plugin_call(): void
    {
        $source = file_get_contents(self::SRC . 'Services/PlayerService.php');

        $at = strpos($source, 'private function rattingActivity(');
        $this->assertNotFalse($at);

        $readSection = substr($source, strpos($source, 'public function primeActivityCache('));

        $this->assertStringNotContainsString('PluginBridge', $readSection);
        $this->assertStringNotContainsString("->call('corp-wallet-manager'", $readSection);
    }

    /**
     * A classification outlives the membership it describes until the nightly
     * prune, and for good in a corp the prune skips. So a corp's
     * classifications are never read by corporation alone: each read either
     * goes through onRoster() or starts from a list of user ids. The prune is
     * the one read that wants the others, and it names them with whereNotIn.
     */
    public function test_no_classification_read_counts_people_who_left(): void
    {
        $reads = 0;
        foreach (self::files('.php') as $path) {
            $source = file_get_contents($path);
            $offset = 0;
            while (($at = strpos($source, 'PlayerClassification::forCorporation(', $offset)) !== false) {
                $chain = substr($source, $at, strpos($source, ';', $at) - $at);
                $this->assertMatchesRegularExpression(
                    "/->where(?:Not)?In\\(\\s*'user_id'/",
                    $chain,
                    basename($path) . " reads a corp's classifications without narrowing to its members"
                );
                $offset = $at + 1;
                $reads++;
            }
        }

        $this->assertGreaterThan(0, $reads, 'the pattern no longer matches anything, so the check checks nothing');
        $this->assertStringContainsString(
            'PlayerClassification::onRoster(',
            file_get_contents(self::SRC . 'Http/Controllers/CorpHealthController.php'),
            'the Corp Health page lists classifications; it has to read the current roster'
        );
    }

    /**
     * The Access tab reads CorpAccessService's result without defaults, so a
     * section one return leaves out is a 500 for every corp that takes that
     * return. A corp with nobody registered did, from 1.0.0: its early return
     * had no people list.
     */
    public function test_every_return_gives_the_access_tab_everything_it_reads(): void
    {
        preg_match_all(
            "/\\$(ax|axS|axD)\\['(\\w+)'\\](?!\\s*\\?\\?)/",
            file_get_contents(self::SRC . 'Resources/views/corp-health/index.blade.php'),
            $reads,
            PREG_SET_ORDER
        );
        $needs = ['ax' => [], 'axS' => [], 'axD' => []];
        foreach ($reads as [, $variable, $key]) {
            $needs[$variable][$key] = true;
        }
        $this->assertArrayHasKey('people', $needs['ax'], 'the pattern no longer finds the reads, so the check checks nothing');
        $this->assertNotEmpty($needs['axS']);
        $this->assertNotEmpty($needs['axD']);

        $source = file_get_contents(self::SRC . 'Services/CorpAccessService.php');
        $start = strpos($source, 'public function forCorporation(');
        $this->assertNotFalse($start);
        preg_match('/\R    \}\R/', $source, $end, PREG_OFFSET_CAPTURE, $start);
        $body = self::withoutClosures(preg_replace('~//[^\n]*|/\*.*?\*/~s', '', substr($source, $start, $end[0][1] - $start)));

        preg_match_all('/\breturn\b/', $body, $returns, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($returns[0]);
        foreach ($returns[0] as [, $at]) {
            $this->assertMatchesRegularExpression(
                '/^return\s*\[/',
                substr($body, $at, 12),
                'forCorporation returns something other than an array literal, which this check cannot read'
            );
            $given = self::literalKeys(self::literalAt($body, strpos($body, '[', $at)));

            foreach (['ax' => null, 'axS' => 'seat', 'axD' => 'discord'] as $variable => $section) {
                $have = $section === null ? array_keys($given) : ($given[$section] ?? []);
                foreach (array_keys($needs[$variable]) as $key) {
                    $this->assertContains($key, $have, "a return from forCorporation gives the Access tab no {$variable}['{$key}']");
                }
            }
        }
    }
}
