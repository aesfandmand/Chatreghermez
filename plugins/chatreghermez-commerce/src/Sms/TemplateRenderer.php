<?php
declare(strict_types=1);

namespace Chatreghermez\Commerce\Sms;

/** Replaces {name} placeholders; unknown placeholders are left out (never leaks raw braces to customers). */
final class TemplateRenderer
{
    /** @param array<string,scalar> $vars */
    public static function render(string $template, array $vars): string
    {
        $out = preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => (string) ($vars[$m[1]] ?? ''), $template);
        return trim(preg_replace('/[ \t]{2,}/', ' ', (string) $out) ?? '');
    }
}
