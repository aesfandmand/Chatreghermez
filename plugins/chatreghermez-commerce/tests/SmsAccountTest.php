<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Tests;

use Chatreghermez\Commerce\Account\OtpPolicy;
use Chatreghermez\Commerce\Sms\FakeSmsProvider;
use Chatreghermez\Commerce\Sms\PhoneNormalizer;
use Chatreghermez\Commerce\Sms\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class SmsAccountTest extends TestCase
{
    public function testPhoneNormalizer(): void
    {
        $this->assertSame('09131234567', PhoneNormalizer::normalize('09131234567'));
        $this->assertSame('09131234567', PhoneNormalizer::normalize('۰۹۱۳۱۲۳۴۵۶۷'));
        $this->assertSame('09131234567', PhoneNormalizer::normalize('+98 913 123 4567'));
        $this->assertSame('09131234567', PhoneNormalizer::normalize('00989131234567'));
        $this->assertSame('09131234567', PhoneNormalizer::normalize('9131234567'));
        $this->assertNull(PhoneNormalizer::normalize('03131234567'));
        $this->assertNull(PhoneNormalizer::normalize('0913123'));
        $this->assertNull(PhoneNormalizer::normalize(''));
    }

    public function testTemplateRender(): void
    {
        $this->assertSame('سفارش CRC-1 ثبت شد.', TemplateRenderer::render('سفارش {order_no} ثبت شد.', ['order_no' => 'CRC-1']));
        $this->assertSame('سلام', TemplateRenderer::render('سلام {unknown}', []));
    }

    public function testOtpPolicy(): void
    {
        $c = OtpPolicy::generate();
        $this->assertTrue(strlen($c) === 6 && ctype_digit($c));
        $h = OtpPolicy::hash('09131234567', '123456', 'k');
        $this->assertTrue(OtpPolicy::verify('09131234567', '123456', $h, 'k'));
        $this->assertFalse(OtpPolicy::verify('09131234567', '123457', $h, 'k'));
        $this->assertFalse(OtpPolicy::verify('09130000000', '123456', $h, 'k'));
        $this->assertFalse(OtpPolicy::canResend(100, 150));
        $this->assertTrue(OtpPolicy::canResend(100, 160));
        $this->assertFalse(OtpPolicy::expired(100, 220));
        $this->assertTrue(OtpPolicy::expired(100, 221));
    }

    public function testFakeProvider(): void
    {
        $p = new FakeSmsProvider();
        $this->assertTrue($p->send('09131234567', 'x'));
        $this->assertCount(1, $p->sent);
    }
}
