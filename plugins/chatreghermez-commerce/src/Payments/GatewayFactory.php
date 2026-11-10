<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Payments;

/** Chooses the gateway from site settings. Credentials live in wp_options set by an admin-only screen, never in the repo. */
final class GatewayFactory
{
    public static function make(): ?GatewayAdapter
    {
        $id = (string) get_option('crc_gateway', '');
        if ($id === 'zibal') {
            $merchant = (string) get_option('crc_zibal_merchant', '');
            return $merchant === '' ? null : new ZibalGateway($merchant);
        }
        return apply_filters('crc_gateway_adapter', null, $id);
    }
}
