<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

final class QrCodeService
{
    /**
     * @return array{content: string, mimeType: string}
     */
    public function renderImage(string $data): array
    {
        if (extension_loaded('gd')) {
            return [
                'content' => $this->build($data, new PngWriter()),
                'mimeType' => 'image/png',
            ];
        }

        return [
            'content' => $this->build($data, new SvgWriter()),
            'mimeType' => 'image/svg+xml',
        ];
    }

    public function renderPng(string $data): string
    {
        return $this->build($data, new PngWriter());
    }

    private function build(string $data, PngWriter|SvgWriter $writer): string
    {
        $result = Builder::create()
            ->writer($writer)
            ->writerOptions([])
            ->data($data)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Low)
            ->size(220)
            ->margin(10)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build();

        return $result->getString();
    }
}
