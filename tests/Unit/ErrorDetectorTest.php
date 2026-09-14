<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Monitoring\ErrorDetector;
use App\Monitoring\Status;
use PHPUnit\Framework\TestCase;

final class ErrorDetectorTest extends TestCase
{
    private ErrorDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ErrorDetector();
    }

    private function wpCriticalPage(): string
    {
        return '<!DOCTYPE html><html><head><title>WordPress &rsaquo; Error</title></head><body id="error-page">'
            . '<div class="wp-die-message"><p>There has been a critical error on this website.</p></div></body></html>';
    }

    public function testDetectsWordPressCriticalErrorOnHttp200(): void
    {
        $result = $this->detector->detect($this->wpCriticalPage(), 200);
        self::assertNotNull($result);
        self::assertSame(Status::CRITICAL_ERROR, $result['status']);
        self::assertSame('wp_critical_error', $result['type']);
        self::assertStringContainsString('critical error', $result['excerpt']);
    }

    public function testDetectsWordPressCriticalErrorOnHttp500(): void
    {
        $result = $this->detector->detect($this->wpCriticalPage(), 500);
        self::assertSame(Status::CRITICAL_ERROR, $result['status'] ?? null);
    }

    public function testDetectsDatabaseError(): void
    {
        $body = '<html><head><title>Database Error</title></head><body><h1>Error establishing a database connection</h1></body></html>';
        $result = $this->detector->detect($body, 500);
        self::assertSame(Status::DATABASE_ERROR, $result['status'] ?? null);
    }

    public function testDetectsMaintenanceMode(): void
    {
        $body = '<html><body><h1>Briefly unavailable for scheduled maintenance. Check back in a minute.</h1></body></html>';
        $result = $this->detector->detect($body, 503);
        self::assertSame(Status::MAINTENANCE, $result['status'] ?? null);
        self::assertSame('wp_maintenance', $result['type']);
    }

    public function testDetectsExposedFatalError(): void
    {
        $body = "<br />\n<b>Fatal error</b>:  Uncaught Error: Call to undefined function foo() in /var/www/wp-content/plugins/x/x.php:12\nStack trace:\n#0 {main}\n  thrown in <b>/var/www/x.php</b> on line <b>12</b><br />";
        $result = $this->detector->detect($body, 200);
        self::assertSame(Status::CRITICAL_ERROR, $result['status'] ?? null);
        self::assertSame('php_fatal_error', $result['type']);
    }

    public function testDetectsMemoryExhaustedWhenRenderingIsCutOff(): void
    {
        // Rendering stopped half-way: no closing tags after the fatal error.
        $body = '<html><body>' . str_repeat('<p>Normal content of a long page.</p>', 2000)
            . "\n<b>Fatal error</b>:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) in /var/www/wp-includes/class-wp-hook.php on line 324";
        $result = $this->detector->detect($body, 200);
        self::assertNotNull($result);
        self::assertSame(Status::CRITICAL_ERROR, $result['status']);
    }

    public function testDetectsFatalErrorPrintedAfterThePage(): void
    {
        $body = '<html><body>' . str_repeat('<p>Normal content of a long page.</p>', 2000) . '</body></html>'
            . "\n<b>Fatal error</b>:  Uncaught Error: Class \"Redis\" not found in /var/www/wp-content/object-cache.php:12";
        self::assertSame(Status::CRITICAL_ERROR, $this->detector->detect($body, 200)['status'] ?? null);
    }

    public function testDetectsFatalErrorPrintedBeforeThePage(): void
    {
        $body = "<br />\n<b>Warning</b>: something<br />\n<b>Fatal error</b>:  Uncaught Error: Call to undefined function get_header() in /var/www/index.php:3<br />\n"
            . '<html><body>' . str_repeat('<p>Normal content of a long page.</p>', 2000) . '</body></html>';
        self::assertSame(Status::CRITICAL_ERROR, $this->detector->detect($body, 200)['status'] ?? null);
    }

    public function testArticleHeadlineAboutFatalErrorsIsNotAnError(): void
    {
        $body = '<html><head><title>Fix "Fatal error: Allowed memory size exhausted"</title></head><body>'
            . '<h1>How to fix "Fatal error: Allowed memory size of 134217728 bytes exhausted"</h1>'
            . str_repeat('<p>Normal content of a long page.</p>', 2000) . '</body></html>';
        self::assertNull($this->detector->detect($body, 200));
    }

    public function testIgnoresErrorTermsInsideArticleContent(): void
    {
        $filler = str_repeat('<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>', 400);
        $body = '<html><body>' . $filler
            . '<article><h2>Fixing "Fatal error: Allowed memory size exhausted"</h2><p>When you see <code>Fatal error: Allowed memory size of 134217728 bytes exhausted</code> raise WP_MEMORY_LIMIT. '
            . 'Warning: always back up. Another error is Access denied for user \'root\'@\'localhost\' (using password: YES).</p>'
            . '<pre>PHP Fatal error:  Uncaught Error: Call to undefined function example()</pre></article>'
            . $filler . '</body></html>';
        self::assertNull($this->detector->detect($body, 200));
    }

    public function testWordPressPhrasesQuotedInsideLargeArticleAreIgnored(): void
    {
        $filler = str_repeat('<p>Lorem ipsum dolor sit amet.</p>', 3000);
        $body = '<html><head><title>How to fix "Error establishing a database connection"</title></head><body>' . $filler
            . '<p>Error establishing a database connection is the most common WordPress error. There has been a critical error on this website is another.</p>' . $filler . '</body></html>';
        self::assertNull($this->detector->detect($body, 200));
    }

    public function testWordPressPhrasesInsideLargePageWithErrorMarkupAreDetected(): void
    {
        $filler = str_repeat('<p>Lorem ipsum dolor sit amet.</p>', 3000);
        $body = '<html><body id="error-page">' . $filler . '<p>Error establishing a database connection</p>' . $filler . '</body></html>';
        self::assertSame(Status::DATABASE_ERROR, $this->detector->detect($body, 200)['status'] ?? null);
        $body = '<html><body>' . $filler . '<p>Error establishing a database connection</p>' . $filler . '</body></html>';
        self::assertSame(Status::DATABASE_ERROR, $this->detector->detect($body, 500)['status'] ?? null, 'HTTP 500 context');
    }

    public function testGenericWordsAreNotTreatedAsErrors(): void
    {
        $body = '<html><body><h1>Warning signs of a bad host</h1><p>Error pages should be friendly. This is not an error.</p></body></html>';
        self::assertNull($this->detector->detect($body, 200));
    }

    public function testServerErrorPageTitles(): void
    {
        self::assertSame(Status::HTTP_500, $this->detector->detect('<html><head><title>500 Internal Server Error</title></head><body></body></html>', 200)['status'] ?? null);
        self::assertSame(Status::HTTP_502, $this->detector->detect('<html><head><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1></body></html>', 502)['status'] ?? null);
        self::assertSame(Status::HTTP_503, $this->detector->detect('<html><body><h1>Service Unavailable</h1></body></html>', 503)['status'] ?? null);
        self::assertSame(Status::HTTP_504, $this->detector->detect('<html><head><title>504 Gateway Time-out</title></head></html>', 504)['status'] ?? null);
    }

    public function testCategoriesCanBeDisabled(): void
    {
        $result = $this->detector->detect($this->wpCriticalPage(), 200, [ErrorDetector::CATEGORY_DATABASE]);
        self::assertNull($result);
        $result = $this->detector->detect($this->wpCriticalPage(), 200, [ErrorDetector::CATEGORY_WP_CRITICAL]);
        self::assertNotNull($result);
    }

    public function testEmptyBody(): void
    {
        self::assertNull($this->detector->detect('', 200));
    }

    public function testLooksLikeWordPress(): void
    {
        self::assertTrue($this->detector->looksLikeWordPress('<link rel="stylesheet" href="/wp-content/themes/x/style.css">'));
        self::assertFalse($this->detector->looksLikeWordPress('<html><body>plain</body></html>'));
    }
}
