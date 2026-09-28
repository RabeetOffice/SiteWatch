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

if (!function_exists('sw_brand_splash')) {
    /**
     * First-load splash: the symbol draws itself once (ring, then the pulse line), then fades out.
     * Styles live inline in header.php so it paints before any stylesheet has loaded.
     */
    function sw_brand_splash(): string
    {
        return '<div class="sw-splash" id="swSplash" aria-hidden="true">'
            . '<svg width="64" height="64" viewBox="0 0 48 48" focusable="false">'
            . '<path class="ring" d="M40.42 19.6A17 17 0 1 1 28.4 7.58" pathLength="100" fill="none" stroke="#EA580C" stroke-width="5.5" stroke-linecap="round"/>'
            . '<circle class="dot" cx="36.8" cy="11.2" r="5.2" fill="#EA580C"/>'
            . '<path class="pulse" d="M10 27.5H17.5L22 16L27 34.5L30.5 27.5H38" pathLength="100" fill="none" stroke="#EA580C" stroke-width="5" stroke-linejoin="miter" stroke-miterlimit="2"/>'
            . '</svg></div>';
    }
}

if (!function_exists('sw_splash_head')) {
    /**
     * Inline splash styles for <head>, so the splash paints before any stylesheet has loaded.
     */
    function sw_splash_head(): string
    {
        return <<<'CSS'
<style>
/* Splash: inline so it paints before any stylesheet arrives. */
.sw-splash { position: fixed; inset: 0; z-index: 3000; display: flex; align-items: center; justify-content: center; background: #F8FAFC; transition: opacity .28s ease, visibility .28s; animation: sw-splash-safety .3s ease 1.6s forwards; }
[data-bs-theme="dark"] .sw-splash { background: #111315; }
html.no-splash .sw-splash { display: none; }
.sw-splash.is-done { opacity: 0; visibility: hidden; }
.sw-splash .ring, .sw-splash .pulse { stroke-dasharray: 100; stroke-dashoffset: 100; }
.sw-splash .ring { animation: sw-draw .5s cubic-bezier(.4, 0, .2, 1) .05s forwards; }
.sw-splash .dot { transform-origin: 36.8px 11.2px; transform: scale(0); animation: sw-pop .22s ease-out .45s forwards; }
.sw-splash .pulse { animation: sw-draw .45s cubic-bezier(.4, 0, .2, 1) .5s forwards; }
@keyframes sw-draw { to { stroke-dashoffset: 0; } }
@keyframes sw-pop { to { transform: scale(1); } }
@keyframes sw-splash-safety { to { opacity: 0; visibility: hidden; } }
</style>
CSS;
    }
}

if (!function_exists('sw_splash_script')) {
    /**
     * Inline head script body: the splash plays once per browser session (a new session starts with each
     * launch of the installed app) and never for people who prefer reduced motion.
     */
    function sw_splash_script(): string
    {
        return "try { var swReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;"
            . " if (swReduced || sessionStorage.getItem('sw-splashed') === '1') { document.documentElement.classList.add('no-splash'); }"
            . " sessionStorage.setItem('sw-splashed', '1'); } catch (e) { document.documentElement.classList.add('no-splash'); }";
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
            'icon-192'  => 'images/icons/icon-192.png',
            'icon-512'  => 'images/icons/icon-512.png',
            'apple'     => 'images/icons/apple-touch-icon.png',
        ];
        return asset($map[$key] ?? $map['logo']);
    }
}
