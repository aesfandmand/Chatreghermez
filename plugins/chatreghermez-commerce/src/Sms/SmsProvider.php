<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Sms;

/** Any SMS vendor implements this; the shop never depends on one vendor. Credentials come from secure settings only. */
interface SmsProvider
{
    public function id(): string;

    /** @return bool true when the vendor accepted the message */
    public function send(string $mobile, string $text): bool;
}
