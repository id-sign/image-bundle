<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\UrlSigner;
use PHPUnit\Framework\TestCase;

class UrlSignerTest extends TestCase
{
    private UrlSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new UrlSigner('test-secret');
    }

    public function testSignReturnsDeterministicSignature(): void
    {
        $sig1 = $this->signer->sign('uploads/photo.jpg', 800, 600, 'cover', 80);
        $sig2 = $this->signer->sign('uploads/photo.jpg', 800, 600, 'cover', 80);

        self::assertSame($sig1, $sig2);
        self::assertSame(16, \strlen($sig1));
    }

    public function testDifferentParamsProduceDifferentSignatures(): void
    {
        $sig1 = $this->signer->sign('uploads/photo.jpg', 800, 600, 'cover', 80);
        $sig2 = $this->signer->sign('uploads/photo.jpg', 640, 600, 'cover', 80);

        self::assertNotSame($sig1, $sig2);
    }

    public function testVerifyValidSignature(): void
    {
        $sig = $this->signer->sign('uploads/photo.jpg', 800, null, null, 80);

        self::assertTrue($this->signer->verify($sig, 'uploads/photo.jpg', 800, null, null, 80));
    }

    public function testVerifyInvalidSignature(): void
    {
        self::assertFalse($this->signer->verify('invalid_sig_here', 'uploads/photo.jpg', 800, null, null, 80));
    }

    public function testDifferentSecretProducesDifferentSignatures(): void
    {
        $otherSigner = new UrlSigner('other-secret');

        $sig1 = $this->signer->sign('photo.jpg', 800, 600, null, 80);
        $sig2 = $otherSigner->sign('photo.jpg', 800, 600, null, 80);

        self::assertNotSame($sig1, $sig2);
    }

    public function testLosslessFlagAltersSignature(): void
    {
        $lossy = $this->signer->sign('photo.jpg', 800, null, null, 80, null, false);
        $lossless = $this->signer->sign('photo.jpg', 800, null, null, 80, null, true);

        self::assertNotSame($lossy, $lossless);
    }

    public function testVerifyRespectsLosslessFlag(): void
    {
        $sig = $this->signer->sign('photo.jpg', 800, null, null, 80, null, true);

        self::assertTrue($this->signer->verify($sig, 'photo.jpg', 800, null, null, 80, null, true));
        self::assertFalse($this->signer->verify($sig, 'photo.jpg', 800, null, null, 80, null, false));
    }

    public function testLosslessAndWatermarkBothAlterSignature(): void
    {
        $a = $this->signer->sign('photo.jpg', 800, null, null, 80, null, true);
        $b = $this->signer->sign('photo.jpg', 800, null, null, 80, 'copyright', true);
        $c = $this->signer->sign('photo.jpg', 800, null, null, 80, 'copyright', false);

        self::assertNotSame($a, $b);
        self::assertNotSame($b, $c);
        self::assertNotSame($a, $c);
    }
}
