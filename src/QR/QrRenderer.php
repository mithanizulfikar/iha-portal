<?php
declare(strict_types=1);

namespace Iha\QR;

/** Draws QR codes with the maintained chillerlan/php-qrcode library (installed through Composer). */
final class QrRenderer
{
    public static function available(): bool
    {
        return class_exists(\chillerlan\QRCode\QRCode::class);
    }

    /** SVG markup for $url, or null when the library is missing or rendering fails. */
    public static function svg(string $url): ?string
    {
        if (!self::available()) {
            return null;
        }
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
                'outputBase64' => false,
                'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
                'addQuietzone' => true,
            ]);
            return (string) (new \chillerlan\QRCode\QRCode($options))->render($url);
        } catch (\Throwable $e) {
            error_log('QR render failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function dataUri(string $url): ?string
    {
        $svg = self::svg($url);
        return $svg === null ? null : 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
