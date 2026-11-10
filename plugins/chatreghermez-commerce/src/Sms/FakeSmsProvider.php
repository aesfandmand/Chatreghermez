<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Sms;

final class FakeSmsProvider implements SmsProvider
{
    /** @var list<array{0:string,1:string}> */
    public array $sent = [];

    public function id(): string { return 'fake'; }

    public function send(string $mobile, string $text): bool
    {
        $this->sent[] = [$mobile, $text];
        return true;
    }
}
