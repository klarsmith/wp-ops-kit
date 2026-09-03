<?php

declare(strict_types=1);

namespace WPOpsKit\Tests\Unit;

use WPOpsKit\Checks;
use WPOpsKit\Tests\TestCase;

/**
 * all_passed() is literally the ready/not-ready decision, and failed_names() is
 * what an anonymous caller is allowed to see. Both are pure array logic, so they
 * are pinned here without any WordPress in the way.
 */
final class ChecksAggregationTest extends TestCase {

    public function test_all_passed_is_true_when_every_check_passes(): void {
        self::assertTrue( Checks::all_passed( [
            'db'        => ['ok' => true, 'detail' => ''],
            'db_schema' => ['ok' => true, 'detail' => '58975'],
        ] ) );
    }

    public function test_all_passed_is_false_when_any_check_fails(): void {
        self::assertFalse( Checks::all_passed( [
            'db'        => ['ok' => true, 'detail' => ''],
            'db_schema' => ['ok' => false, 'detail' => 'upgrade pending'],
        ] ) );
    }

    /**
     * Documents a deliberate edge: an empty result set is "passed". Checks::run()
     * always returns four checks, so this can only be reached by a future change
     * that makes every check conditional — at which point a pod with no checks
     * at all would report ready. Pinned so that change has to be deliberate.
     */
    public function test_empty_results_are_treated_as_passing(): void {
        self::assertTrue( Checks::all_passed( [] ) );
        self::assertSame( [], Checks::failed_names( [] ) );
    }

    public function test_failed_names_lists_only_failures_in_order(): void {
        $results = [
            'db'           => ['ok' => true, 'detail' => ''],
            'db_schema'    => ['ok' => false, 'detail' => 'upgrade pending'],
            'object_cache' => ['ok' => true, 'detail' => 'external'],
            'uploads'      => ['ok' => false, 'detail' => 'not writable'],
        ];

        self::assertSame( ['db_schema', 'uploads'], Checks::failed_names( $results ) );
    }

    /**
     * The names are handed to unauthenticated callers, so they must stay free of
     * the detail strings — "db_version 57155 != core 58975" is as useful to an
     * attacker fingerprinting the install as it is to an operator.
     */
    public function test_failed_names_carry_no_detail(): void {
        $names = Checks::failed_names( [
            'db_schema' => ['ok' => false, 'detail' => 'db_version 57155 != core 58975 (upgrade pending)'],
        ] );

        self::assertSame( ['db_schema'], $names );
    }
}
