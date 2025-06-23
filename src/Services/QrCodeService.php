<?php
namespace WFOT\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class QrCodeService
{
    /**
     * Generates a QR code and returns it as a base64 encoded data URI.
     *
     * @param string $data The data to encode in the QR code.
     * @return string The QR code as a data URI.
     */
    public static function generateDataUri(string $data): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $result = $builder->build();

        return $result->getDataUri();
    }
}
