<?php

// Run with: php tests/public-navigation.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function expectSame($expected, $actual, string $context): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "$context\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function renderPage(string $page): DOMXPath
{
    ob_start();
    try {
        require __DIR__ . "/../$page";
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    $document = new DOMDocument();
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($document);
}

function checkLinks(DOMNodeList $links, array $expected, ?string $current, string $region): void
{
    expectSame(count($expected), $links->length, "$region link count");
    foreach ($links as $index => $link) {
        [$path, $label] = $expected[$index];
        expectSame("https://navigation.test$path", $link->getAttribute('href'), "$region link $index destination");
        if ($label !== null) {
            $text = trim(preg_replace('/[\t\r\n \x{2002}]+/u', ' ', $link->textContent));
            expectSame($label, $text, "$region $path label");
        }
        $ariaCurrent = $link->hasAttribute('aria-current') ? $link->getAttribute('aria-current') : null;
        expectSame($path === $current ? 'page' : null, $ariaCurrent, "$region $path current");
        if ($path === $current) {
            expectSame(true, $link->hasAttribute('class'), "$region $path current link has class");
            expectSame('', $link->getAttribute('class'), "$region $path current link class");
        }
    }
}

$pages = [
    'index.php' => ['/', null, false],
    'about.php' => ['/about', '/about', false],
    'work.php' => ['/work', '/work', false],
    'contact.php' => ['/contact', '/contact', false],
    'contact-success.php' => [null, null, true],
    'contact-failure.php' => [null, null, true],
];
$headerLinks = [
    ['/', null],
    ['/about', 'About'],
    ['/about#john-levis', 'John Levis'],
    ['/about#mission-vision', 'Mission | Vision'],
    ['/work', 'Work'],
    ['/work#seminars-and-workshops', "Professional development seminars and\u{00A0}workshops"],
    ['/work#mentoring', "One on one mentoring\u{00A0}and small group\u{00A0}sessions"],
    ['/contact', 'Contact'],
    ['/contact#contact-form', 'Email'],
    ['/contact#call', 'Call'],
];
$barLinks = [['/about', 'About'], ['/work', 'Work'], ['/contact', 'Contact']];
$menuHooks = [
    'logo' => './/div[@class="logo-box"]/a/svg[@class="header-logo" and @id="Layer_1"]',
    'open button' => './/nav[@class="mainnav"]//div[@class="bm-burger-button"]/button[@id="react-burger-menu-btn" and normalize-space(.)="Open Menu"]',
    'overlay' => './/div[@class="bm-overlay" and contains(@style, "opacity: 0") and contains(@style, "translate3d(100%, 0px, 0px)")]',
    'closed drawer' => './/div[@class="bm-menu-wrap" and @hidden and @aria-hidden="true" and contains(@style, "translate3d(100%, 0px, 0px)")]',
    'menu list' => './/div[@class="bm-menu-wrap"]/div[@class="bm-menu"]/nav[@class="bm-item-list"]/ul[@class="bm-item" and @tabindex="-1"]',
    'close button' => './/div[@class="bm-menu-wrap"]//div[@class="bm-cross-button"]/button[@id="react-burger-cross-btn" and @tabindex="-1" and normalize-space(.)="Close Menu"]',
];

// Supply only the public pages' local configuration from outside the worktree.
$fixture = exec('mktemp -d', $output, $status);
if ($status !== 0 || $fixture === '') {
    throw new RuntimeException('Could not create the navigation fixture directory');
}
$includePath = get_include_path();
try {
    mkdir("$fixture/private");
    file_put_contents(
        "$fixture/private/environment_variables.php",
        "<?php defined('DOMAIN') || define('DOMAIN', 'navigation.test');"
    );
    set_include_path($fixture . PATH_SEPARATOR . $includePath);

    foreach ($pages as $page => [$headerCurrent, $barCurrent, $slidesOut]) {
        $dom = renderPage($page);
        $headers = $dom->query('//*[@id="outer-container"]/header[@class="header"][following-sibling::*[1][self::main[@id="page-wrap"]]]');
        expectSame(1, $headers->length, "$page header before page content");
        $header = $headers->item(0);
        checkLinks($dom->query('.//a', $header), $headerLinks, $headerCurrent, "$page header");

        $bars = $dom->query('//*[@id="page-wrap"]/div[following-sibling::*[1][self::section[@class="one-col-section footer-section"]]]'
            . '/div[@id="animated-example"][preceding-sibling::*[1][self::main]]');
        expectSame(1, $bars->length, "$page bottom bar between page content and footer");
        $bar = $bars->item(0);
        expectSame('bottom-bar animated row' . ($slidesOut ? ' slideOut' : ''), $bar->getAttribute('class'), "$page bottom bar class");
        checkLinks($dom->query('.//a', $bar), $barLinks, $barCurrent, "$page bottom bar");
        foreach (['aboutCard', 'workCard', 'contactCard'] as $id) {
            expectSame(1, $dom->query("./div[@id='$id']/a", $bar)->length, "$page bottom bar $id");
            if ($page === 'index.php') {
                expectSame(1, $dom->query("//*[@id='homepage']//div[@id='$id']/a")->length, "$page content $id");
            }
        }

        foreach ($menuHooks as $hook => $xpath) {
            expectSame(1, $dom->query($xpath, $header)->length, "$page $hook");
        }
        echo "PASS $page\n";
    }

    foreach (['renderHeader', 'renderBottomBar'] as $render) {
        ob_start();
        try {
            PublicNavigation::$render('contact-resutl');
        } catch (InvalidArgumentException | TypeError) {
            continue;
        } finally {
            ob_end_clean();
        }
        throw new RuntimeException("$render accepted an unknown public page identity");
    }
    echo "PASS unknown public page identity\n";
} finally {
    set_include_path($includePath);
    unlink("$fixture/private/environment_variables.php");
    rmdir("$fixture/private");
    rmdir($fixture);
}
