<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Orders\LineTypes;
use Chatreghermez\Commerce\Orders\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testHappyPath(): void
    {
        $this->assertTrue(OrderStatus::canTransition('draft', 'pending_payment'));
        $this->assertTrue(OrderStatus::canTransition('pending_payment', 'paid'));
        $this->assertTrue(OrderStatus::canTransition('paid', 'in_progress'));
        $this->assertTrue(OrderStatus::canTransition('in_progress', 'delivered'));
    }

    public function testIllegalJumpsAreRejected(): void
    {
        $this->assertFalse(OrderStatus::canTransition('draft', 'paid'));
        $this->assertFalse(OrderStatus::canTransition('pending_payment', 'delivered'));
        $this->assertFalse(OrderStatus::canTransition('delivered', 'cancelled'));
        $this->assertFalse(OrderStatus::canTransition('expired', 'paid'));
    }

    public function testFinalStates(): void
    {
        foreach (['delivered', 'cancelled', 'expired'] as $s) {
            $this->assertTrue(OrderStatus::isFinal($s));
        }
        $this->assertFalse(OrderStatus::isFinal('paid'));
    }

    public function testLineTypeRegistryAllowsRentalExtension(): void
    {
        $this->assertSame('product', LineTypes::invoiceKind('product_purchase'));
        $this->assertFalse(LineTypes::isKnown('media_rental'));
        LineTypes::register('media_rental', 'rental');
        $this->assertSame('rental', LineTypes::invoiceKind('media_rental'));
    }
}
