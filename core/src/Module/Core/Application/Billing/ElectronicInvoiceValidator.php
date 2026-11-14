<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Runs installed, pinned validators locally. It never downloads tools or sends invoice data. */
final readonly class ElectronicInvoiceValidator
{
    public const VERSION = 'KoSIT 1.6.3 / XRechnung 3.0.2 2026-08-31 / EN16931 1.3.16 / veraPDF 1.30.4';

    public function __construct(#[Autowire('%kernel.project_dir%/var/einvoice-validator')] private string $directory)
    {
    }

    public function ready(string $format): bool
    {
        return is_file($this->directory.'/VERSION')
            && self::VERSION === trim((string) file_get_contents($this->directory.'/VERSION'))
            && is_file($this->directory.'/kosit/validator.jar')
            && is_file($this->directory.'/kosit/configuration/scenarios.xml')
            && is_file($this->directory.'/kosit/configuration/EN16931-CII-validation.xsl')
            && ('zugferd' !== $format || is_executable($this->directory.'/verapdf/verapdf'));
    }

    public function validate(string $xml, string $format, ?string $pdf = null): void
    {
        if (!in_array($format, ['xrechnung', 'zugferd'], true) || !$this->ready($format)) {
            throw new \RuntimeException('billing.einvoice_validator_missing');
        }
        $temporary = sys_get_temp_dir().'/easywi-einvoice-'.bin2hex(random_bytes(16));
        if (!mkdir($temporary, 0700)) {
            throw new \RuntimeException('billing.einvoice_validation_unavailable');
        }
        try {
            file_put_contents($temporary.'/invoice.xml', $xml);
            $jar = $this->directory.'/kosit/validator.jar';
            $config = $this->directory.'/kosit/configuration';
            if ('xrechnung' === $format) {
                $this->run(['java', '-Xmx192m', '-jar', $jar, '-s', $config.'/scenarios.xml', '-r', $config, '-o', $temporary, $temporary.'/invoice.xml']);
                $report = $this->readReport($temporary.'/invoice-report.xml');
                if ('true' !== $report->documentElement?->getAttribute('valid') || 1 !== $report->getElementsByTagNameNS('http://www.xoev.de/de/validator/varl/1', 'accept')->length) {
                    throw new \InvalidArgumentException('billing.einvoice_rules_failed');
                }
            } else {
                $this->run(['java', '-Xmx192m', '-cp', $jar, 'net.sf.saxon.Transform', '-s:'.$temporary.'/invoice.xml', '-xsl:'.$config.'/EN16931-CII-validation.xsl', '-o:'.$temporary.'/svrl.xml']);
                $report = $this->readReport($temporary.'/svrl.xml');
                if (0 !== $report->getElementsByTagNameNS('http://purl.oclc.org/dsdl/svrl', 'failed-assert')->length || 'schematron-output' !== $report->documentElement?->localName) {
                    throw new \InvalidArgumentException('billing.einvoice_rules_failed');
                }
                if (null === $pdf || strlen($pdf) > 20_000_000) {
                    throw new \InvalidArgumentException('billing.einvoice_pdf_failed');
                }
                file_put_contents($temporary.'/invoice.pdf', $pdf);
                $output = $this->run([$this->directory.'/verapdf/verapdf', '--format', 'xml', '--flavour', '3b', $temporary.'/invoice.pdf']);
                $report = $this->xml($output);
                $validations = $report->getElementsByTagName('validationReport');
                if (1 !== $validations->length || 'true' !== $validations->item(0)?->attributes?->getNamedItem('isCompliant')?->nodeValue) {
                    throw new \InvalidArgumentException('billing.einvoice_pdf_failed');
                }
            }
        } finally {
            // Reports may contain full invoice data; remove them on success and failure.
            (new Filesystem())->remove($temporary);
        }
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = new Process($arguments, null, ['JAVA_OPTS' => '-Xmx192m', 'JAVA_TOOL_OPTIONS' => false, '_JAVA_OPTIONS' => false]);
        $process->setTimeout(45);
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $exception) {
            throw new \RuntimeException('billing.einvoice_validation_unavailable', 0, $exception);
        }
        if (!$process->isSuccessful()) {
            throw new \InvalidArgumentException('billing.einvoice_rules_failed');
        }

        return $process->getOutput();
    }

    private function readReport(string $file): \DOMDocument
    {
        if (!is_file($file) || filesize($file) > 8_000_000) {
            throw new \RuntimeException('billing.einvoice_validation_unavailable');
        }

        return $this->xml((string) file_get_contents($file));
    }

    private function xml(string $content): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if ($content === '' || strlen($content) > 8_000_000 || !$document->loadXML($content, LIBXML_NONET) || null !== $document->doctype) {
                throw new \RuntimeException('billing.einvoice_validation_unavailable');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
