<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Turns ClientReportService::build() data into the branded report: an HTML page (share link and preview) or
 * a PDF. Both use one template, includes/client-report.php, written for Dompdf's CSS support (tables rather than
 * flexbox or grid). Charts are drawn here as SVG and embedded as data: URIs, so the PDF renderer never fetches
 * anything over the network (remote loading stays off).
 */
final class ClientReportRenderer
{
    private const CHART_W = 1000;
    private const CHART_H = 220;

    /**
     * @param array<string, mixed> $data   ClientReportService::build() output
     * @param array<string, mixed> $opts   logo_src (URL or data URI), pdf_url, nonce, font_url
     */
    public function html(array $data, string $mode, array $opts = []): string
    {
        $data['charts'] = [
            'availability' => self::availabilityChart($data['daily']),
            'response'     => self::responseChart($data['daily'], (string) $data['brand']['primary_color']),
        ];
        $render = static function (array $report, string $mode, array $opts): string {
            ob_start();
            try {
                require SW_ROOT . '/includes/client-report.php';
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };
        return $render($data, $mode, $opts);
    }

    /**
     * @param array<string, mixed> $data
     * @return string PDF bytes
     */
    public function pdf(array $data, ?string $logoDataUri): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new RuntimeException('PDF export needs the dompdf/dompdf package. Run composer install on the server.');
        }
        $cache = rtrim((string) App::config()->get('app.paths.cache'), '/\\') . DIRECTORY_SEPARATOR . 'dompdf';
        if (!is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultMediaType('print');
        $options->setDefaultPaperSize('a4');
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setChroot([SW_ROOT . '/vendor/dompdf/dompdf']);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($data, 'pdf', ['logo_src' => $logoDataUri]), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Footer on every page: a hairline, the brand and contact details on the left, page numbers on the right.
        // Drawn on the canvas rather than as a fixed element, which Dompdf places unreliably in the page margin.
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $regular = $metrics->getFont('DejaVu Sans');
        $bold = $metrics->getFont('DejaVu Sans', 'bold') ?? $regular;
        if ($regular !== null) {
            $w = $canvas->get_width();
            $h = $canvas->get_height();
            $left = 42.5;
            $right = $w - 42.5;
            $y = $h - 40;
            $size = 7.2;
            $grey = [0.39, 0.45, 0.55];
            $canvas->page_line($left, $y - 7, $right, $y - 7, [0.886, 0.91, 0.941], 0.6);
            $brand = $data['brand'];
            $name = (string) $brand['name'];
            $canvas->page_text($left, $y, $name, $bold, $size, [0.06, 0.09, 0.16]);
            $contact = array_values(array_filter([
                $brand['footer_text'] ?? null,
                !empty($brand['website']) ? preg_replace('#^https?://#i', '', rtrim((string) $brand['website'], '/')) : null,
                $brand['email'] ?? null,
                $brand['phone'] ?? null,
            ]));
            if ($contact !== []) {
                $rest = '  ·  ' . implode('  ·  ', $contact);
                $room = $right - 90 - ($left + $metrics->getTextWidth($name, $bold, $size));
                while ($rest !== '' && $metrics->getTextWidth($rest, $regular, $size) > $room) {
                    $rest = mb_substr($rest, 0, -2);
                    $rest = rtrim($rest) . '…';
                    if (mb_strlen($rest) < 6) {
                        $rest = '';
                    }
                }
                $canvas->page_text($left + $metrics->getTextWidth($name, $bold, $size), $y, $rest, $regular, $size, $grey);
            }
            $canvas->page_text($right - 62, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $regular, $size, $grey);
        }
        $dompdf->addInfo('Title', (string) $data['title'] . ' · ' . (string) $data['range']['label']);
        $dompdf->addInfo('Author', (string) ($data['brand']['prepared_by'] ?: $data['brand']['name']));
        $dompdf->addInfo('Creator', 'SiteWatch');

        return (string) $dompdf->output();
    }

    /**
     * File name for a downloaded PDF.
     *
     * @param array{from: string, to: string} $range
     */
    public static function fileName(string $brand, string $title, array $range): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $brand . ' ' . $title), '-'));
        return ($slug !== '' ? mb_substr($slug, 0, 80) : 'website-report') . '-' . $range['from'] . '_' . $range['to'] . '.pdf';
    }

    // ------------------------------------------------------------------
    // Charts
    // ------------------------------------------------------------------

    /**
     * Daily uptime bars. The scale starts just under the worst day so small dips stay visible; the template
     * prints the scale next to the chart.
     *
     * @param list<array{date: string, uptime: ?float}> $daily
     * @return array{src: string, floor: int, has_data: bool}
     */
    public static function availabilityChart(array $daily): array
    {
        $values = array_values(array_filter(array_column($daily, 'uptime'), static fn ($v): bool => $v !== null));
        $min = $values === [] ? 100.0 : (float) min($values);
        $floor = (int) max(0, min(99, floor($min) - ($min >= 99.5 ? 1 : 2)));
        $n = max(1, count($daily));
        $slot = self::CHART_W / $n;
        $gap = min(6.0, $slot * 0.28);
        $h = self::CHART_H;

        $svg = self::grid(4);
        foreach ($daily as $i => $d) {
            $x = $i * $slot + $gap / 2;
            $w = max(1.0, $slot - $gap);
            if ($d['uptime'] === null) {
                $svg .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="4" rx="1" fill="#E2E8F0"/>', $x, $h - 4, $w);
                continue;
            }
            $u = (float) $d['uptime'];
            $bar = max(4.0, ($u - $floor) / (100 - $floor) * ($h - 6));
            $color = $u >= 99.9 ? '#16A34A' : ($u >= 99.0 ? '#65A30D' : ($u >= 97.0 ? '#F59E0B' : '#DC2626'));
            $svg .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.1f" fill="%s"/>', $x, $h - $bar, $w, $bar, min(3.0, $w / 3), $color);
        }
        return ['src' => self::dataUri($svg), 'floor' => $floor, 'has_data' => $values !== []];
    }

    /**
     * Daily average response time as a line with a soft area underneath, in the brand colour.
     *
     * @param list<array{date: string, avg: ?float}> $daily
     * @return array{src: string, max: int, has_data: bool, fastest: ?array{date: string, avg: float}, slowest: ?array{date: string, avg: float}}
     */
    public static function responseChart(array $daily, string $color): array
    {
        $points = array_values(array_filter($daily, static fn (array $d): bool => $d['avg'] !== null && $d['avg'] > 0));
        $max = $points === [] ? 1000 : (float) max(array_column($points, 'avg'));
        $top = self::niceCeil($max * 1.12);
        $n = count($daily);
        $h = self::CHART_H;
        $x = static fn (int $i): float => $n <= 1 ? self::CHART_W / 2 : $i * (self::CHART_W / ($n - 1));
        $y = static fn (float $v): float => $h - 4 - ($v / $top) * ($h - 12);

        $svg = self::grid(4);
        // Days without data are skipped, so the line joins the days on either side.
        $line = [];
        foreach ($daily as $i => $d) {
            if ($d['avg'] !== null && $d['avg'] > 0) {
                $line[] = [$x($i), $y((float) $d['avg'])];
            }
        }
        if (count($line) === 1) {
            $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="6" fill="%s"/>', $line[0][0], $line[0][1], $color);
        } elseif ($line !== []) {
            $path = implode(' ', array_map(static fn (array $p): string => sprintf('%.2f,%.2f', $p[0], $p[1]), $line));
            $area = sprintf('%.2f,%d ', $line[0][0], $h) . $path . sprintf(' %.2f,%d', $line[count($line) - 1][0], $h);
            $svg .= '<polygon points="' . $area . '" fill="' . $color . '" fill-opacity="0.12"/>';
            $svg .= '<polyline points="' . $path . '" fill="none" stroke="' . $color . '" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round"/>';
            if (count($line) <= 45) {
                foreach ($line as $p) {
                    $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="4.5" fill="#FFFFFF" stroke="%s" stroke-width="3"/>', $p[0], $p[1], $color);
                }
            }
        }

        $fastest = null;
        $slowest = null;
        foreach ($points as $p) {
            if ($fastest === null || $p['avg'] < $fastest['avg']) {
                $fastest = ['date' => $p['date'], 'avg' => (float) $p['avg']];
            }
            if ($slowest === null || $p['avg'] > $slowest['avg']) {
                $slowest = ['date' => $p['date'], 'avg' => (float) $p['avg']];
            }
        }
        return ['src' => self::dataUri($svg), 'max' => $top, 'has_data' => $points !== [], 'fastest' => $fastest, 'slowest' => $slowest];
    }

    private static function grid(int $lines): string
    {
        $out = '';
        for ($i = 0; $i <= $lines; $i++) {
            $y = 2 + $i * ((self::CHART_H - 4) / $lines);
            $out .= sprintf('<line x1="0" y1="%.2f" x2="%d" y2="%.2f" stroke="%s" stroke-width="1.5"/>', $y, self::CHART_W, $y, $i === $lines ? '#CBD5E1' : '#EEF2F6');
        }
        return $out;
    }

    private static function dataUri(string $inner): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::CHART_W . '" height="' . self::CHART_H . '" viewBox="0 0 '
            . self::CHART_W . ' ' . self::CHART_H . '" preserveAspectRatio="none">' . $inner . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function niceCeil(float $value): int
    {
        if ($value <= 0) {
            return 100;
        }
        $magnitude = 10 ** floor(log10($value));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($step * $magnitude >= $value) {
                return (int) ($step * $magnitude);
            }
        }
        return (int) (10 * $magnitude);
    }

    // ------------------------------------------------------------------
    // Colours
    // ------------------------------------------------------------------

    /** Mix a hex colour with white; $amount 0 = unchanged, 1 = white. */
    public static function tint(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $mix = static fn (int $c): int => (int) round($c + (255 - $c) * $amount);
        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /** Mix a hex colour with black; $amount 0 = unchanged, 1 = black. */
    public static function shade(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $mix = static fn (int $c): int => (int) round($c * (1 - $amount));
        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /** Readable text colour (white or near-black) on a background. */
    public static function onColor(string $hex): string
    {
        [$r, $g, $b] = array_map(static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return $luminance > 0.45 ? '#0F172A' : '#FFFFFF';
    }

    /**
     * A brand colour dark enough to use for text on white (headings, links).
     */
    public static function readable(string $hex): string
    {
        return self::onColor($hex) === '#0F172A' ? self::shade($hex, 0.45) : $hex;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            $hex = 'EA580C';
        }
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
