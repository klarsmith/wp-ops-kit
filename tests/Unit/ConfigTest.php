<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use WPOpsKit\Config;
use WPOpsKit\Tests\TestCase;

/**
 * Config is the plugin's whole security boundary: token() alone decides whether
 * /metrics is served at all, and the constant-over-environment precedence is
 * what makes a wp-config.php value authoritative over a leaked env var.
 */
final class ConfigTest extends TestCase {

    public function test_constant_wins_over_environment(): void {
        define( 'WP_OPS_TEST_PRECEDENCE', 'from-constant' );
        $this->env( 'WP_OPS_TEST_PRECEDENCE', 'from-env' );

        self::assertSame( 'from-constant', Config::get( 'WP_OPS_TEST_PRECEDENCE' ) );
    }

    public function test_environment_is_used_when_no_constant_is_defined(): void {
        $this->env( 'WP_OPS_TEST_ENV_ONLY', 'from-env' );

        self::assertSame( 'from-env', Config::get( 'WP_OPS_TEST_ENV_ONLY' ) );
    }

    public function test_default_is_returned_when_nothing_is_set(): void {
        self::assertSame( 'fallback', Config::get( 'WP_OPS_TEST_ABSENT', 'fallback' ) );
        self::assertNull( Config::get( 'WP_OPS_TEST_ABSENT' ) );
    }

    /**
     * An env var set to the empty string means "unset" here. Kubernetes injects
     * empty values freely (an unset ConfigMap key, a blank Helm value), and
     * treating those as configured would silently disable defaults.
     */
    public function test_empty_environment_value_falls_through_to_the_default(): void {
        $this->env( 'WP_OPS_TEST_EMPTY', '' );

        self::assertSame( 'fallback', Config::get( 'WP_OPS_TEST_EMPTY', 'fallback' ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'truthy_values' )]
    public function test_bool_accepts_the_documented_truthy_spellings( string $value ): void {
        $this->env( 'WP_OPS_TEST_BOOL', $value );

        self::assertTrue( Config::bool( 'WP_OPS_TEST_BOOL' ) );
    }

    /** @return array<string, list<string>> */
    public static function truthy_values(): array {
        return [
            'one'         => ['1'],
            'true'        => ['true'],
            'yes'         => ['yes'],
            'on'          => ['on'],
            'mixed case'  => ['TrUe'],
            'upper on'    => ['ON'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'falsy_values' )]
    public function test_bool_rejects_everything_else( string $value ): void {
        $this->env( 'WP_OPS_TEST_BOOL_FALSE', $value );

        self::assertFalse( Config::bool( 'WP_OPS_TEST_BOOL_FALSE', false ) );
    }

    /** @return array<string, list<string>> */
    public static function falsy_values(): array {
        return [
            'zero'      => ['0'],
            'false'     => ['false'],
            'off'       => ['off'],
            'no'        => ['no'],
            'nonsense'  => ['maybe'],
        ];
    }

    public function test_bool_returns_the_default_when_unset(): void {
        self::assertTrue( Config::bool( 'WP_OPS_TEST_BOOL_MISSING', true ) );
        self::assertFalse( Config::bool( 'WP_OPS_TEST_BOOL_MISSING', false ) );
    }

    public function test_int_casts_and_defaults(): void {
        $this->env( 'WP_OPS_TEST_INT', '42' );

        self::assertSame( 42, Config::int( 'WP_OPS_TEST_INT', 7 ) );
        self::assertSame( 7, Config::int( 'WP_OPS_TEST_INT_MISSING', 7 ) );
    }

    public function test_list_splits_trims_and_drops_empties(): void {
        $this->env( 'WP_OPS_TEST_LIST', ' a/a.php , ,b/b.php,  ' );

        self::assertSame( ['a/a.php', 'b/b.php'], Config::list( 'WP_OPS_TEST_LIST' ) );
    }

    public function test_list_is_empty_when_unset(): void {
        self::assertSame( [], Config::list( 'WP_OPS_TEST_LIST_MISSING' ) );
    }

    /**
     * A constant may legitimately be declared as an array in wp-config.php, in
     * which case it must not be run through the CSV splitter.
     */
    public function test_list_accepts_an_array_constant(): void {
        define( 'WP_OPS_TEST_LIST_ARRAY', ['a/a.php', '', 'b/b.php'] );

        self::assertSame( ['a/a.php', 'b/b.php'], Config::list( 'WP_OPS_TEST_LIST_ARRAY' ) );
    }

    /**
     * The single most consequential branch in the plugin. A null token disables
     * the metrics endpoint outright; anything that wrongly returns a non-null
     * value here turns the exporter into an open one.
     */
    public function test_token_is_null_when_unset(): void {
        self::assertNull( Config::token() );
    }

    public function test_token_is_null_when_set_to_the_empty_string(): void {
        $this->env( 'WP_OPS_TOKEN', '' );

        self::assertNull( Config::token() );
    }

    public function test_token_is_returned_when_configured(): void {
        $this->env( 'WP_OPS_TOKEN', 's3cret' );

        self::assertSame( 's3cret', Config::token() );
    }
}
