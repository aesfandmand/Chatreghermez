<?php
declare(strict_types=1);

namespace Chatreghermez\MediaRental\Tests;

use Chatreghermez\MediaRental\Domain\FiscalCalendar;
use Chatreghermez\MediaRental\Domain\SlotRules;
use Chatreghermez\MediaRental\Domain\WaitQueue;
use Chatreghermez\MediaRental\Import\MediaSheetRules;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    public function testFiscalCalendar(): void
    {
        $this->assertSame('اردیبهشت', FiscalCalendar::monthName(1));
        $this->assertSame('فروردین', FiscalCalendar::monthName(12));
        $this->assertSame([1405, 1], FiscalCalendar::fromJalali(1405, 2));   // Ordibehesht 1405 starts fiscal 1405
        $this->assertSame([1405, 12], FiscalCalendar::fromJalali(1406, 1));  // Farvardin 1406 closes fiscal 1405
        $this->assertSame([1405, 11], FiscalCalendar::fromJalali(1405, 12));
        $this->assertSame([2, 5], FiscalCalendar::normalizeMonths([5, 2, 5]));
    }

    public function testSlotLifecycle(): void
    {
        $now = 1000000;
        $free = ['state' => 'free'];
        $this->assertTrue(SlotRules::canLock($free, 7, $now));
        $locked = ['state' => 'locked', 'holder' => 7, 'locked_until' => $now + 100];
        $this->assertFalse(SlotRules::canLock($locked, 8, $now));          // someone else is selecting
        $this->assertTrue(SlotRules::canLock($locked, 7, $now));           // same holder may continue
        $this->assertSame('free', SlotRules::effectiveState($locked, $now + 101)); // lock lapsed
        $res = ['state' => 'reserved', 'holder' => 7, 'reserved_until' => $now + 3 * 86400 - 1];
        $this->assertFalse(SlotRules::canLock($res, 8, $now));
        $this->assertSame(3, SlotRules::daysLeft($res, $now));
        $this->assertSame('free', SlotRules::effectiveState($res, $now + 3 * 86400));  // forfeited -> back to pool
        $this->assertTrue(SlotRules::canFinalize($res, 7, $now));
        $this->assertFalse(SlotRules::canFinalize($res, 8, $now));
        $this->assertFalse(SlotRules::canLock(['state' => 'blocked'], 7, $now));
        $this->assertFalse(SlotRules::canLock(['state' => 'final', 'holder' => 7], 7, $now));
        $this->assertSame('grey', SlotRules::colour('blocked'));
    }

    public function testWaitQueueOnePerHour(): void
    {
        $e = [['id' => 2, 'created_at' => 20, 'notified_at' => 0], ['id' => 1, 'created_at' => 10, 'notified_at' => 0], ['id' => 3, 'created_at' => 5, 'notified_at' => 99]];
        $this->assertSame(1, WaitQueue::next($e, 0, 1000)['id']);
        $this->assertNull(WaitQueue::next($e, 1000, 1000 + 3599));
        $this->assertSame(1, WaitQueue::next($e, 1000, 1000 + 3600)['id']);
        $this->assertNull(WaitQueue::next([], 0, 5));
    }

    public function testSheetRules(): void
    {
        $this->assertSame('free', MediaSheetRules::cell('')['status']);
        $this->assertSame('free', MediaSheetRules::cell('  ')['status']);
        $this->assertSame('blocked', MediaSheetRules::cell('−')['status']);
        $this->assertSame('blocked', MediaSheetRules::cell('_')['status']);
        $this->assertSame('reserved', MediaSheetRules::cell('پرسفید')['status']);
        $this->assertSame(MediaSheetRules::dedupeKey('پرسفید'), MediaSheetRules::dedupeKey("پر سفید "));
        $this->assertSame(MediaSheetRules::dedupeKey('كالا'), MediaSheetRules::dedupeKey('کالا'));
        $this->assertSame('100613', MediaSheetRules::systemCode('۱۰۰۶۱۳'));
        $this->assertNull(MediaSheetRules::systemCode('1006'));
        $loc = MediaSheetRules::parseLocation('بیلبورد ۶×۳ میدان امام');
        $this->assertSame('بیلبورد', $loc['type']);
        $this->assertSame(6.0, $loc['width_m']);
        $this->assertTrue($loc['confident']);
        $this->assertFalse(MediaSheetRules::parseLocation('میدان امام')['confident']);
        $lb = MediaSheetRules::parseLocation('لایت باکس افقی 3*2 جنب بانک');
        $this->assertSame('لایت باکس', $lb['type']);
        $this->assertTrue($lb['lit']);
        $this->assertSame('landscape', $lb['orientation']);
        $cm = MediaSheetRules::parseLocation('لایت 180*120 بلوار');
        $this->assertSame(1.8, $cm['width_m']);
        $this->assertSame(1.2, $cm['height_m']);
    }

    public function testHeartbeatKeepsLockWhileCustomerIsPresent(): void
    {
        $t0 = 1000000;
        $until = $t0 + SlotRules::LOCK_SECONDS;
        // Still inside the first 15 minutes: the guaranteed window is never shortened.
        $this->assertSame($until, SlotRules::heartbeatUntil($t0, $until, $t0 + 60));
        // After 15 minutes a ping keeps the lock alive for the presence window only.
        $this->assertSame($t0 + 1000 + 180, SlotRules::heartbeatUntil($t0, $until, $t0 + 1000));
        // Past the hard limit no more extension: null -> the lock lapses.
        $this->assertNull(SlotRules::heartbeatUntil($t0, $until, $t0 + 10801));
    }
}
