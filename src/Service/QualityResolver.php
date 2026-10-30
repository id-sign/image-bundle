<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

/**
 * Output quality of a variant: the per-image value, then format_quality, then default_quality.
 *
 * A per-image int applies to every format, a per-image map only to the formats it names — the others keep the
 * configured quality, so raising one image's WebP quality does not flatten a tuned AVIF value.
 */
class QualityResolver
{
    /**
     * @param array<string, int> $formatQuality output format => quality, from id_sign_image.format_quality
     */
    public function __construct(
        private readonly int $defaultQuality,
        private readonly array $formatQuality = [],
    ) {
    }

    /**
     * @param int|array<string, int>|null $quality per-image value, already checked by assertValid()
     */
    public function resolve(string $format, int|array|null $quality = null): int
    {
        if (\is_int($quality)) {
            return $quality;
        }

        return $quality[$format] ?? $this->formatQuality[$format] ?? $this->defaultQuality;
    }

    /**
     * A typo in a map key would silently fall back to the configured quality; fail where the value is written.
     *
     * @param int|array<mixed>|null $quality
     *
     * @throws \InvalidArgumentException when $quality is not 1…100 or a map of output format => 1…100
     */
    public function assertValid(int|array|null $quality, string $src): void
    {
        if (null === $quality) {
            return;
        }

        if (\is_int($quality)) {
            self::assertInRange($quality, $src);

            return;
        }

        foreach ($quality as $format => $value) {
            if (!\in_array($format, FormatNegotiator::OUTPUT_FORMATS, true)) {
                throw new \InvalidArgumentException(\sprintf('Quality for src "%s" names an unknown output format "%s"; use %s (the bundle writes "jpeg", never "jpg").', $src, $format, implode(', ', FormatNegotiator::OUTPUT_FORMATS)));
            }

            self::assertInRange($value, $src);
        }
    }

    private static function assertInRange(mixed $value, string $src): void
    {
        if (!\is_int($value) || $value < 1 || $value > 100) {
            throw new \InvalidArgumentException(\sprintf('Quality for src "%s" must be an integer 1-100 (got %s).', $src, \is_int($value) ? $value : get_debug_type($value)));
        }
    }
}
