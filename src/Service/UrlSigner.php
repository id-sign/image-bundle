<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use Symfony\Contracts\Service\ResetInterface;

class UrlSigner implements ResetInterface
{
    /** @var array<string, string> signature per payload, kept for one request */
    private array $signatures = [];

    private readonly string $derivedKey;

    public function __construct(string $secret)
    {
        $this->derivedKey = hash_hmac('sha256', 'id_sign_image', $secret, true);
    }

    public function sign(string $src, int $width, ?int $height, ?string $fit, int $quality, ?string $watermark = null, bool $lossless = false): string
    {
        $payload = $this->buildPayload($src, $width, $height, $fit, $quality, $watermark, $lossless);

        // The payload carries no format, so one HMAC covers every <source> and the fallback of a width.
        // The memo lives for one request; reset() clears it between requests in worker mode.
        return $this->signatures[$payload] ??= substr(hash_hmac('sha256', $payload, $this->derivedKey), 0, 16);
    }

    public function reset(): void
    {
        $this->signatures = [];
    }

    public function verify(string $signature, string $src, int $width, ?int $height, ?string $fit, int $quality, ?string $watermark = null, bool $lossless = false): bool
    {
        $expected = $this->sign($src, $width, $height, $fit, $quality, $watermark, $lossless);

        return hash_equals($expected, $signature);
    }

    private function buildPayload(string $src, int $width, ?int $height, ?string $fit, int $quality, ?string $watermark, bool $lossless): string
    {
        return \sprintf('%s|%d|%s|%s|%d|%s|%s', $src, $width, $height ?? '', $fit ?? '', $quality, $watermark ?? '', $lossless ? '1' : '');
    }
}
