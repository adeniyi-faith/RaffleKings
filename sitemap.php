<?php
/**
 * Simple Static Sitemap for RaffleKings
 * Lists only the core frontend pages.
 */

// 1. Set Headers
header("HTTP/1.1 200 OK");
header("Content-Type: application/xml; charset=utf-8");
header("X-Robots-Tag: noindex"); 

// 2. Define Base URL
// Since we are not loading WP, we define this manually or detect it.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_url = $protocol . $_SERVER['HTTP_HOST'];

// 3. Output XML
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    <!-- Homepage -->
    <url>
        <loc><?php echo $base_url; ?>/</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- Raffles Page -->
    <url>
        <loc><?php echo $base_url; ?>/raffles</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>

    <!-- Registration -->
    <url>
        <loc><?php echo $base_url; ?>/register</loc>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- Login -->
    <url>
        <loc><?php echo $base_url; ?>/login</loc>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- Hall of Fame / Winners -->
    <url>
        <loc><?php echo $base_url; ?>/hall-of-fame</loc>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>



</urlset>