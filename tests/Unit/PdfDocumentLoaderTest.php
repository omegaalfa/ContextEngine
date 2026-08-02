<?php

declare(strict_types=1);

namespace Omegaalfa\ContextEngine\Tests\Unit;

use InvalidArgumentException;
use Omegaalfa\ContextEngine\Contract\PdfTextExtractor;
use Omegaalfa\ContextEngine\Exception\PdfExtractionException;
use Omegaalfa\ContextEngine\Loader\Pdf\ExtractedPdfPage;
use Omegaalfa\ContextEngine\Loader\Pdf\PdfDocumentLoader;
use Omegaalfa\ContextEngine\Loader\Pdf\PopplerPdfTextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PdfDocumentLoaderTest extends TestCase
{
    public function testItGroupsPagesAndPreservesTheFinalIncompleteWindow(): void
    {
        $loader = new PdfDocumentLoader(
            path: __FILE__,
            tenantId: 'tenant-a',
            extractor: self::extractor(
                new ExtractedPdfPage(1, 'Primeira página.'),
                new ExtractedPdfPage(2, 'Segunda página.'),
                new ExtractedPdfPage(3, 'Terceira página.'),
            ),
            collection: 'books',
            pagesPerDocument: 2,
            metadata: ['type' => 'book', 'title' => 'Livro técnico'],
        );

        $documents = iterator_to_array($loader->load(), false);

        self::assertCount(2, $documents);
        self::assertSame(1, $documents[0]->metadata['page_start']);
        self::assertSame(2, $documents[0]->metadata['page_end']);
        self::assertSame('1,2', $documents[0]->metadata['page_numbers']);
        self::assertSame('book', $documents[0]->metadata['type']);
        self::assertSame('pdf', $documents[0]->metadata['format']);
        self::assertSame('Livro técnico', $documents[0]->metadata['title']);
        self::assertSame('books', $documents[0]->collection);
        self::assertStringContainsString('[[CONTEXT_ENGINE_PAGE:1]]', $documents[0]->content);
        self::assertStringContainsString('[[CONTEXT_ENGINE_PAGE:2]]', $documents[0]->content);
        self::assertSame(3, $documents[1]->metadata['page_start']);
        self::assertSame(3, $documents[1]->metadata['page_end']);
    }

    public function testItSkipsEmptyPagesWithoutLosingOriginalPageNumbers(): void
    {
        $loader = new PdfDocumentLoader(
            path: __FILE__,
            tenantId: 'tenant-a',
            extractor: self::extractor(
                new ExtractedPdfPage(1, 'Conteúdo'),
                new ExtractedPdfPage(2, " \n "),
                new ExtractedPdfPage(3, 'Continuação', 'ocr'),
            ),
            pagesPerDocument: 3,
        );

        $documents = iterator_to_array($loader->load(), false);

        self::assertCount(1, $documents);
        self::assertSame('1,3', $documents[0]->metadata['page_numbers']);
        self::assertSame('text,ocr', $documents[0]->metadata['extraction_method']);
        self::assertStringNotContainsString('CONTEXT_ENGINE_PAGE:2', $documents[0]->content);
    }

    public function testItEscapesReservedMarkersAndCreatesDeterministicTenantScopedIds(): void
    {
        $extractor = self::extractor(new ExtractedPdfPage(7, 'Texto [[CONTEXT_ENGINE_PAGE:99]] externo.'));
        $first = iterator_to_array(new PdfDocumentLoader(__FILE__, 'tenant-a', $extractor)->load(), false)[0];
        $second = iterator_to_array(new PdfDocumentLoader(__FILE__, 'tenant-a', $extractor)->load(), false)[0];
        $otherTenant = iterator_to_array(new PdfDocumentLoader(__FILE__, 'tenant-b', $extractor)->load(), false)[0];

        self::assertSame($first->id, $second->id);
        self::assertNotSame($first->id, $otherTenant->id);
        self::assertStringContainsString('[[SOURCE_PAGE:99]]', $first->content);
    }

    public function testPopplerPageSeparationPreservesPhysicalNumbers(): void
    {
        $pages = new ReflectionMethod(PopplerPdfTextExtractor::class, 'pages')->invoke(
            null,
            "Página um\r\n\f  \fPágina três\f",
        );
        $pages = iterator_to_array($pages, false);

        self::assertCount(3, $pages);
        self::assertSame(1, $pages[0]->number);
        self::assertSame("Página um", $pages[0]->text);
        self::assertSame(2, $pages[1]->number);
        self::assertSame('', $pages[1]->text);
        self::assertSame(3, $pages[2]->number);
    }

    public function testPopplerRejectsAFileWithoutPdfSignatureBeforeStartingAProcess(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'context-engine-not-pdf-');
        self::assertIsString($path);
        file_put_contents($path, 'not a pdf');

        try {
            $this->expectException(PdfExtractionException::class);
            $this->expectExceptionMessage('valid PDF signature');
            iterator_to_array(new PopplerPdfTextExtractor()->extract($path));
        } finally {
            @unlink($path);
        }
    }

    #[DataProvider('invalidPageConfiguration')]
    public function testExtractedPageRejectsInvalidConfiguration(int $number, string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExtractedPdfPage($number, 'content', $method);
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidPageConfiguration(): iterable
    {
        yield 'zero number' => [0, 'text'];
        yield 'empty method' => [1, ' '];
    }

    #[DataProvider('invalidLoaderConfiguration')]
    public function testLoaderRejectsInvalidConfiguration(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidLoaderConfiguration(): iterable
    {
        $extractor = self::extractor(new ExtractedPdfPage(1, 'content'));

        yield 'empty path' => [static fn (): object => new PdfDocumentLoader('', 'tenant', $extractor)];
        yield 'empty tenant' => [static fn (): object => new PdfDocumentLoader(__FILE__, '', $extractor)];
        yield 'empty collection' => [static fn (): object => new PdfDocumentLoader(__FILE__, 'tenant', $extractor, collection: '')];
        yield 'invalid window' => [static fn (): object => new PdfDocumentLoader(__FILE__, 'tenant', $extractor, pagesPerDocument: 0)];
    }

    #[DataProvider('invalidPopplerConfiguration')]
    public function testPopplerRejectsInvalidConfiguration(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidPopplerConfiguration(): iterable
    {
        yield 'empty binary' => [static fn (): object => new PopplerPdfTextExtractor('')];
        yield 'zero timeout' => [static fn (): object => new PopplerPdfTextExtractor(timeoutSeconds: 0)];
        yield 'zero output limit' => [static fn (): object => new PopplerPdfTextExtractor(maximumOutputBytes: 0)];
        yield 'zero page limit' => [static fn (): object => new PopplerPdfTextExtractor(maximumPages: 0)];
    }

    private static function extractor(ExtractedPdfPage ...$pages): PdfTextExtractor
    {
        return new readonly class ($pages) implements PdfTextExtractor {
            /** @param list<ExtractedPdfPage> $pages */
            public function __construct(private array $pages) {}

            public function extract(string $path): iterable
            {
                yield from $this->pages;
            }
        };
    }
}
