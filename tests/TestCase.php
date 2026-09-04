<?php

declare(strict_types=1);

namespace WPOpsKit\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Shared base for the unit suite.
 *
 * Several classes under test expose their interesting logic through private
 * static methods — the wire format of the Prometheus exposition, the cron walk,
 * the token comparison. Those are not implementation trivia we should skip;
 * they are the parts most likely to be silently wrong. Rather than widen the
 * API purely for tests, `inScope()` binds a closure to the class so private
 * members are reachable, including by-reference parameters that reflection
 * handles poorly.
 */
abstract class TestCase extends PHPUnitTestCase {

    // Counts Mockery expectations towards PHPUnit's assertion total, so a test
    // whose whole point is "this hook was added" is not reported as risky.
    use MockeryPHPUnitIntegration;

    /** @var list<string> Environment keys set during a test, cleared on teardown. */
    private array $env = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        foreach ( $this->env as $key ) {
            putenv( $key );
        }
        $this->env = [];

        unset( $GLOBALS['wpdb'], $GLOBALS['wp_db_version'], $GLOBALS['wp_version'], $GLOBALS['wp'] );
        unset( $_SERVER['REQUEST_URI'] );

        Monkey\tearDown();
        parent::tearDown();
    }

    /** Set an environment variable for the duration of one test. */
    protected function env( string $key, string $value ): void {
        putenv( $key . '=' . $value );
        $this->env[] = $key;
    }

    /**
     * Bind a closure into a class's scope so it can reach private members.
     *
     * @param class-string $class
     */
    protected function inScope( string $class, \Closure $fn ): \Closure {
        $bound = \Closure::bind( $fn, null, $class );
        if ( null === $bound ) {
            self::fail( 'Could not bind closure into ' . $class );
        }

        return $bound;
    }

    /**
     * Parse Prometheus exposition text into name{labels} => value, dropping
     * HELP/TYPE lines so assertions read as data rather than string matching.
     *
     * @return array<string, string>
     */
    protected function parseExposition( string $text ): array {
        $samples = [];
        foreach ( explode( "\n", trim( $text ) ) as $line ) {
            if ( '' === $line || str_starts_with( $line, '#' ) ) {
                continue;
            }
            $split = strrpos( $line, ' ' );
            self::assertNotFalse( $split, 'Malformed exposition line: ' . $line );
            $samples[ substr( $line, 0, $split ) ] = substr( $line, $split + 1 );
        }

        return $samples;
    }
}
