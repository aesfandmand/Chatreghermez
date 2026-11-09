<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Rest;

use Chatreghermez\Commerce\Access\Capabilities;
use Chatreghermez\Commerce\Access\RoleManager;

/** GET/POST /wp-json/chatreghermez/v1/roles-matrix — permissions checked server-side on every call. */
final class RolesController
{
    public const NS = 'chatreghermez/v1';

    public static function register(): void
    {
        register_rest_route(self::NS, '/roles-matrix', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'get'],
                'permission_callback' => static fn (): bool => current_user_can(Capabilities::ROLES_VIEW),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'set'],
                'permission_callback' => static fn (): bool => current_user_can(Capabilities::ROLES_MANAGE),
                'args'                => [
                    'role'    => ['type' => 'string',  'required' => true],
                    'cap'     => ['type' => 'string',  'required' => true],
                    'granted' => ['type' => 'boolean', 'required' => true],
                ],
            ],
        ]);
    }

    public static function get(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'roles'    => Capabilities::managedRoles(),
            'registry' => Capabilities::registry(),
            'matrix'   => RoleManager::matrix(),
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function set(\WP_REST_Request $request)
    {
        $result = RoleManager::setCapability(
            sanitize_key((string) $request['role']),
            sanitize_key((string) $request['cap']),
            (bool) $request['granted'],
            get_current_user_id()
        );
        if ($result instanceof \WP_Error) {
            return $result;
        }
        return self::get();
    }
}
