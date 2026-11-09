<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Access\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testEveryDefaultCapabilityIsRegistered(): void
    {
        foreach (Capabilities::defaultMatrix() as $role => $caps) {
            $this->assertTrue(Capabilities::isManagedRole($role), "unknown role {$role}");
            foreach ($caps as $cap) {
                $this->assertTrue(Capabilities::isCapability($cap), "{$role} has unregistered {$cap}");
            }
        }
    }

    public function testEveryManagedRoleHasADefaultEntry(): void
    {
        $this->assertSame(
            array_keys(Capabilities::managedRoles()),
            array_keys(Capabilities::defaultMatrix())
        );
    }

    public function testCustomerHasNoStaffCapabilities(): void
    {
        $this->assertSame([], Capabilities::defaultMatrix()[Capabilities::ROLE_CUSTOMER]);
    }

    public function testDangerousCapabilitiesAreNotGrantedToOperationalRoles(): void
    {
        $matrix = Capabilities::defaultMatrix();
        foreach ([Capabilities::ROLE_SECRETARY, Capabilities::ROLE_MEDIA_SUPERVISOR, Capabilities::ROLE_DESIGNER, Capabilities::ROLE_INSTALLER] as $role) {
            $this->assertNotContains(Capabilities::ROLES_MANAGE, $matrix[$role]);
            $this->assertNotContains(Capabilities::ORDERS_REFUND, $matrix[$role]);
            $this->assertNotContains(Capabilities::GATEWAY_SETTINGS, $matrix[$role]);
        }
    }

    public function testMediaSupervisorCanEditMediaButNotMoney(): void
    {
        $caps = Capabilities::defaultMatrix()[Capabilities::ROLE_MEDIA_SUPERVISOR];
        $this->assertContains(Capabilities::MEDIA_EDIT, $caps);
        $this->assertNotContains(Capabilities::PAYMENTS_RECORD, $caps);
        $this->assertNotContains(Capabilities::INVOICES_APPROVE, $caps);
    }

    public function testManagerCanViewAndChangeRoles(): void
    {
        $caps = Capabilities::defaultMatrix()[Capabilities::ROLE_MANAGER];
        $this->assertContains(Capabilities::ROLES_VIEW, $caps);
        $this->assertContains(Capabilities::ROLES_MANAGE, $caps);
    }
}
