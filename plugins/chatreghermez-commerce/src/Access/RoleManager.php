<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Access;

use Chatreghermez\Commerce\Audit\AuditLog;

/**
 * Applies the role/capability matrix to native WordPress roles.
 * Native roles are the single source of truth, so current_user_can() works everywhere.
 */
final class RoleManager
{
    /** Create managed roles (seeding defaults only for roles that do not exist yet). */
    public static function install(): void
    {
        $defaults = Capabilities::defaultMatrix();
        foreach (Capabilities::managedRoles() as $slug => $label) {
            if (get_role($slug) === null) {
                $caps = ['read' => true];
                foreach ($defaults[$slug] ?? [] as $cap) {
                    $caps[$cap] = true;
                }
                add_role($slug, $label, $caps);
            }
        }
        $admin = get_role('administrator');
        if ($admin) {
            foreach (Capabilities::all() as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /**
     * Live matrix for display.
     * @return array<string,array<string,bool>> role => cap => granted
     */
    public static function matrix(): array
    {
        $out = [];
        foreach (array_keys(Capabilities::managedRoles()) as $slug) {
            $role = get_role($slug);
            foreach (Capabilities::all() as $cap) {
                $out[$slug][$cap] = $role ? !empty($role->capabilities[$cap]) : false;
            }
        }
        return $out;
    }

    /** @return true|\WP_Error */
    public static function setCapability(string $role, string $cap, bool $grant, int $actorId)
    {
        if (!Capabilities::isManagedRole($role)) {
            return new \WP_Error('crc_bad_role', 'نقش نامعتبر است.', ['status' => 400]);
        }
        if (!Capabilities::isCapability($cap)) {
            return new \WP_Error('crc_bad_cap', 'مجوز نامعتبر است.', ['status' => 400]);
        }
        $obj = get_role($role);
        if ($obj === null) {
            return new \WP_Error('crc_role_missing', 'نقش پیدا نشد.', ['status' => 404]);
        }
        $before = !empty($obj->capabilities[$cap]);
        if ($before === $grant) {
            return true;
        }
        $grant ? $obj->add_cap($cap) : $obj->remove_cap($cap);
        AuditLog::record(
            $actorId,
            'roles.capability_changed',
            'role',
            $role,
            [$cap => $before],
            [$cap => $grant]
        );
        return true;
    }
}
