<?php

namespace HrManager\Tests\Fake;

/**
 * Stands in for Mining Manager's ledger model. HR only checks that the class
 * is there, as its way of asking whether Mining Manager is installed, so the
 * tests run as though it is and reach the mining branch.
 *
 * The not-installed case lands in the same place by a different route: the
 * read is also guarded on the table existing, and a test that leaves
 * mining_ledger out of Fake\Rows exercises that.
 */
final class MiningLedger extends Model
{
    protected static string $table = 'mining_ledger';
    protected static string $key = 'id';
}
