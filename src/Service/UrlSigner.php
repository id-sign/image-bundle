<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

class UrlSigner
{
    private readonly string $derivedKey;

    public function __construct(string $secret)
    {
        $this->derivedKey = hash_hmac('sha256', 'id_sign_image', $secret, true);
    }

    public function sign(string $src, int $width, ?int $height, ?string $fit, int $quality, ?string $watermark = null, bool $lossless = false): string
    {
        return substr(hash_hmac('sha256', $this->buildPayload($src, $width, $height, $fit, $quality, $watermark, $lossless), $this->derivedKey), 0, 16);
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
