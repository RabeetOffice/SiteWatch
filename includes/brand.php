<?php

declare(strict_types=1);

/**
 * SiteWatch brand component.
 *
 * Single source of truth for the logo. The symbol geometry below is identical to
 * assets/images/sitewatch-mark.svg (and favicon.svg); the full lockup matches
 * assets/images/sitewatch-logo.svg / sitewatch-logo-dark.svg.
 *
 * The wordmark is rendered with `currentColor` so one component serves both
 * themes: light surfaces inherit the graphite text colour, dark surfaces inherit
 * the light one. The symbol always keeps its brand orange.
 *
 * The viewBox is cropped tight to the visible artwork (no transparent padding),
 * so the logo aligns with navigation and form content without optical offsets.
 */

if (!function_exists('sw_brand_symbol_svg')) {
    /**
     * Inner markup of the SiteWatch symbol, drawn on a 48x48 grid.
     */
    function sw_brand_symbol_svg(): string
    {
        return '<path d="M40.42 19.6A17 17 0 1 1 28.4 7.58" fill="none" stroke="#EA580C" stroke-width="5.5" stroke-linecap="round"/>'
            . '<circle cx="36.8" cy="11.2" r="5.2" fill="#EA580C"/>'
            . '<path d="M10 27.5H17.5L22 16L27 34.5L30.5 27.5H38" fill="none" stroke="#EA580C" stroke-width="5" stroke-linejoin="miter" stroke-miterlimit="2"/>';
    }
}

if (!function_exists('sw_brand_logo')) {
    /**
     * Full SiteWatch lockup (symbol + wordmark). Aspect ratio 430:96 is fixed.
     *
     * @param int         $width Rendered width in px.
     * @param string|null $class Extra classes for the <svg> element.
     */
    function sw_brand_logo(int $width = 158, ?string $class = null): string
    {
        $height = (int) round($width * 96 / 430);
        return '<svg class="sw-logo' . ($class !== null ? ' ' . e($class) : '') . '" width="' . $width . '" height="' . $height . '"'
            . ' viewBox="0 0 430 96" role="img" aria-label="SiteWatch" focusable="false">'
            . '<g transform="scale(2)">' . sw_brand_symbol_svg() . '</g>'
            . '<text x="118" y="71.5" class="sw-logo-word" font-size="66" font-weight="800" letter-spacing="-1"'
            . ' textLength="312" lengthAdjust="spacing" fill="currentColor">SiteWatch</text>'
            . '</svg>';
    }
}

if (!function_exists('sw_brand_mark')) {
    /**
     * Symbol-only derivative, for the collapsed sidebar and other tight spaces.
     */
    function sw_brand_mark(int $size = 30, ?string $class = null): string
    {
        return '<svg class="sw-logo-mark' . ($class !== null ? ' ' . e($class) : '') . '" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 48 48" role="img" aria-label="SiteWatch" focusable="false">'
            . sw_brand_symbol_svg()
            . '</svg>';
    }
}

if (!function_exists('sw_brand_asset')) {
    /**
     * Centralised references to the standalone brand files.
     */
    function sw_brand_asset(string $key = 'logo'): string
    {
        $map = [
            'logo'      => 'images/sitewatch-logo.svg',
            'logo-dark' => 'images/sitewatch-logo-dark.svg',
            'mark'      => 'images/sitewatch-mark.svg',
            'favicon'   => 'images/favicon.svg',
        ];
        return asset($map[$key] ?? $map['logo']);
    }
}
