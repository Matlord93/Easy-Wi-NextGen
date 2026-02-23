<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer as BaconWriter;
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
        try {
            if (extension_loaded('gd')) {
                return [
                    'content' => $this->buildWithEndroid($data, new PngWriter()),
                    'mimeType' => 'image/png',
                ];
            }

            return [
                'content' => $this->buildWithEndroid($data, new SvgWriter()),
                'mimeType' => 'image/svg+xml',
            ];
        } catch (\Throwable) {
            return [
                'content' => $this->buildWithBaconSvg($data),
                'mimeType' => 'image/svg+xml',
            ];
        }
    }

    public function renderPng(string $data): string
    {
        try {
            return $this->buildWithEndroid($data, new PngWriter());
        } catch (\Throwable) {
            return $this->buildWithBaconSvg($data);
        }
    }

    private function buildWithEndroid(string $data, PngWriter|SvgWriter $writer): string
    {
        $result = (new Builder())
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

    private function buildWithBaconSvg(string $data): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220, 10), new SvgImageBackEnd());
        $writer = new BaconWriter($renderer);

        return $writer->writeString($data);
    }
}
