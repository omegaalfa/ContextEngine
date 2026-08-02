<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Loader\Pdf;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\PdfTextExtractor;
use Omegaalfa\ContextEngine\Exception\PdfExtractionException;

/** Extracts ordered pages with Poppler's pdftotext without invoking a shell. */
final readonly class PopplerPdfTextExtractor implements PdfTextExtractor
{
    public function __construct(
        private string $binary = 'pdftotext',
        private int $timeoutSeconds = 60,
        private int $maximumOutputBytes = 50_000_000,
        private ?int $maximumPages = null,
    ) {
        if (trim($binary) === '' || str_contains($binary, "\0")) {
            throw new InvalidArgumentException('Poppler binary cannot be empty or contain null bytes.');
        }
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('PDF extraction timeout must be greater than zero.');
        }
        if ($maximumOutputBytes < 1) {
            throw new InvalidArgumentException('PDF extraction output limit must be greater than zero.');
        }
        if ($maximumPages !== null && $maximumPages < 1) {
            throw new InvalidArgumentException('Maximum PDF pages must be greater than zero.');
        }
    }

    public function extract(string $path): iterable
    {
        $canonicalPath = realpath($path);
        if ($canonicalPath === false || !is_file($canonicalPath) || !is_readable($canonicalPath)) {
            throw new PdfExtractionException('PDF file does not exist or is not readable.');
        }
        $header = @file_get_contents($canonicalPath, false, null, 0, 5);
        if ($header !== '%PDF-') {
            throw new PdfExtractionException('Input file does not have a valid PDF signature.');
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'context-engine-pdf-');
        if ($outputPath === false) {
            throw new PdfExtractionException('Unable to allocate temporary PDF extraction output.');
        }

        try {
            $command = [$this->binary, '-layout', '-enc', 'UTF-8'];
            if ($this->maximumPages !== null) {
                $command[] = '-l';
                $command[] = (string) $this->maximumPages;
            }
            $command[] = $canonicalPath;
            $command[] = $outputPath;

            $this->execute($command);
            clearstatcache(true, $outputPath);
            $size = filesize($outputPath);
            if ($size === false) {
                throw new PdfExtractionException('Unable to inspect PDF extraction output.');
            }
            if ($size > $this->maximumOutputBytes) {
                throw new PdfExtractionException('PDF extraction output exceeds the configured size limit.');
            }
            $text = file_get_contents($outputPath);
            if ($text === false) {
                throw new PdfExtractionException('Unable to read PDF extraction output.');
            }

            yield from self::pages($text);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command): void
    {
        $pipes = [];
        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new PdfExtractionException('Unable to start the Poppler PDF extractor.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $startedAt = hrtime(true);
        $stderr = '';

        try {
            while (true) {
                $stderr .= stream_get_contents($pipes[2]);
                stream_get_contents($pipes[1]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if ((hrtime(true) - $startedAt) / 1_000_000_000 >= $this->timeoutSeconds) {
                    proc_terminate($process);
                    throw new PdfExtractionException('Poppler PDF extraction timed out.');
                }
                usleep(10_000);
            }
        } finally {
            $stderr .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closeCode = proc_close($process);
        }

        if ($exitCode !== 0 && $closeCode !== 0) {
            $detail = trim($stderr);
            throw new PdfExtractionException($detail === ''
                ? 'Poppler PDF extraction failed.'
                : 'Poppler PDF extraction failed: ' . mb_substr($detail, 0, 500));
        }
    }

    /** @return iterable<ExtractedPdfPage> */
    private static function pages(string $text): iterable
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $pages = explode("\f", $text);
        if (trim($pages[array_key_last($pages)]) === '') {
            array_pop($pages);
        }

        foreach ($pages as $index => $page) {
            yield new ExtractedPdfPage($index + 1, trim($page), 'text');
        }
    }
}
