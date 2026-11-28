<?php

// Run with: php tests/content-editor.php
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

function request(string $url, ?array $post = null, string $cookie = ''): array
{
    $headers = $cookie === '' ? [] : ["Cookie: $cookie"];
    $options = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5];
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['method'] = 'POST';
        $options['content'] = http_build_query($post);
    }
    $options['header'] = $headers;
    $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
    if ($body === false) {
        throw new RuntimeException("Request failed: $url");
    }
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = explode(': ', $line, 2);
        $responseHeaders[strtolower($name)] = $value;
    }
    return [(int) explode(' ', $http_response_header[0])[1], $responseHeaders, $body];
}

function pageDom(string $html): DOMXPath
{
    $document = new DOMDocument();
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($document);
}

function formFields(DOMXPath $page): array
{
    $fields = [];
    $form = '//form[input[@name="action" and @value="save"]]';
    foreach ($page->query("$form//*[@name and (self::input or self::textarea)]") as $control) {
        $name = $control->getAttribute('name');
        if (preg_match('/^f\[(.+)\]$/', $name, $match)) {
            expectSame('2000', $control->getAttribute('maxlength'), "$name character limit");
            $fields['f'][$match[1]] = $control->tagName === 'textarea'
                // DOMDocument retains the first newline that a browser discards.
                ? preg_replace('/^\n/', '', $control->textContent)
                : $control->getAttribute('value');
        } else {
            $fields[$name] = $control->getAttribute('value');
        }
    }
    return $fields;
}

$root = dirname(__DIR__);
$fixture = exec('mktemp -d', $output, $status);
if ($status !== 0 || $fixture === '') {
    throw new RuntimeException('Could not create the content editor fixture directory');
}
$server = null;

try {
    $site = "$fixture/site";
    $data = "$site/private/data";
    mkdir($data, 0700, true);
    foreach (['admin', 'components', 'partials'] as $dir) {
        mkdir("$site/$dir", 0700);
    }
    mkdir("$fixture/sessions", 0700);
    $routes = ['/' => 'index.php', '/about' => 'about.php', '/work' => 'work.php', '/contact' => 'contact.php',
        '/contact-success' => 'contact-success.php', '/contact-failure' => 'contact-failure.php'];
    $phpFiles = [
        ...array_values($routes),
        'admin/index.php', 'components/head_contents.php', 'partials/footer.php', 'partials/public-navigation.php',
        'private/content.php', 'private/content_fields.php',
    ];
    if (is_file("$root/private/atomic_write.php")) {
        $phpFiles[] = 'private/atomic_write.php';
    }
    foreach ($phpFiles as $file) {
        copy("$root/$file", "$site/$file");
    }
    file_put_contents("$site/private/environment_variables.php", "<?php define('DOMAIN', 'editor-fixture.test');");
    $password = 'fixture-password';
    $email = 'editor@example.test';
    $users = [$email => ['hash' => password_hash($password, PASSWORD_DEFAULT), 'created' => 0]];
    file_put_contents("$data/admin_users.php", '<?php return ' . var_export($users, true) . ';');
    $router = "$fixture/router.php";
    file_put_contents($router, '<?php $routes = ' . var_export($routes, true) . ';' . <<<'PHP'

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (isset($routes[$path])) {
    require $_SERVER['DOCUMENT_ROOT'] . '/' . $routes[$path];
    return true;
}
return false;
PHP
    );

    $server = proc_open(
        [PHP_BINARY, '-d', "session.save_path=$fixture/sessions", '-S', '127.0.0.1:0', '-t', $site, $router],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$fixture/server.log", 'a'], 2 => ['file', "$fixture/server.log", 'a']],
        $pipes,
        $site
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the content editor fixture server');
    }
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (preg_match('/127\.0\.0\.1:(\d+)\) started/', file_get_contents("$fixture/server.log"), $match)) {
            break;
        }
        usleep(100000);
    }
    if (!isset($match[1])) {
        throw new RuntimeException('Content editor fixture server did not start');
    }
    $url = "http://127.0.0.1:$match[1]";
    $admin = "$url/admin/";

    [$status, $headers, $body] = request($admin);
    expectSame(200, $status, 'sign-in page');
    $cookie = explode(';', $headers['set-cookie'], 2)[0];
    $csrf = pageDom($body)->evaluate('string(//input[@name="csrf"]/@value)');
    $save = ['action' => 'save', 'csrf' => $csrf, 'f' => ['home.hero.heading' => 'Unexpected save']];
    [$status, , $body] = request($admin, $save, $cookie);
    expectSame(200, $status, 'signed-out save returns login form');
    expectSame([], formFields(pageDom($body)), 'signed-out request has no content fields');
    $contentPath = "$data/content.json";
    expectSame(false, file_exists($contentPath), 'signed-out save does not write content');

    [$status, $headers] = request($admin, ['action' => 'login', 'csrf' => $csrf, 'email' => $email, 'password' => $password], $cookie);
    expectSame(302, $status, 'fixture editor signs in');
    expectSame('/admin/', $headers['location'], 'sign-in redirect');
    $cookie = explode(';', $headers['set-cookie'], 2)[0];
    [$status, , $body] = request($admin, null, $cookie);
    expectSame(200, $status, 'signed-in Content Editor');
    $editor = pageDom($body);
    $save = formFields($editor);
    expectSame('Deepening coach impact', $save['f']['home.hero.heading'], 'admin shows built-in wording');
    expectSame(1, $editor->query('//input[@name="f[home.hero.heading]" and @type="text"]')->length, 'hero input kind');
    expectSame(1, $editor->query('//textarea[@name="f[about.hero.heading]" and @rows="3"]')->length, 'About textarea kind');
    expectSame('— Shown on the contact page and used for the tap-to-call link.', $editor->evaluate('normalize-space(//label[input[@name="f[contact.phone]"]]//span[@class="help"])'), 'phone help');

    $phone = '555.0100" data-fixture="phone';
    $tagline = 'Fixture tagline & <sport>';
    $save['f'] = [
        'home.hero.heading' => 'A <coach> & friends',
        'about.hero.heading' => "First <line>\r\nSecond & final",
        'about.bio.christ' => '3 & years | <captain> | A <story> | fourth',
        'work.hero.subhead' => 'Fixture work heading',
        'contact.phone' => $phone,
        'shared.footer.tagline' => $tagline,
    ] + $save['f'];
    [$status, $headers] = request("$admin?fixture=save", $save, $cookie);
    expectSame(302, $status, 'successful save redirect');
    expectSame('/admin/', $headers['location'], 'save redirect strips query');
    [$status, , $body] = request($url . $headers['location'], null, $cookie);
    expectSame(200, $status, 'redirected Content Editor');
    $editor = pageDom($body);
    expectSame('Saved. Your changes are now live on the site.', $editor->evaluate('string(//div[@class="flash ok"])'), 'save success flash');
    $current = formFields($editor);
    expectSame('A <coach> & friends', $current['f']['home.hero.heading'], 'saved editor heading');
    expectSame("First <line>\nSecond & final", $current['f']['about.hero.heading'], 'saved editor textarea');
    expectSame($phone, $current['f']['contact.phone'], 'saved editor phone');

    $public = [];
    foreach ($routes as $path => $file) {
        [$status, , $body] = request($url . $path);
        expectSame(200, $status, "$path public render");
        $public[$path] = pageDom($body);
        expectSame($tagline, $public[$path]->evaluate('string(//p[@class="subhead tagline"])'), "$path edited footer tagline");
    }
    expectSame('A <coach> & friends', $public['/']->evaluate('string(//h1)'), 'public heading is escaped');
    $about = $public['/about'];
    expectSame("First <line>\nSecond & final", $about->evaluate('string(//h1[@class="about-hero-title"])'), 'public About heading is escaped');
    expectSame(1, $about->query('//h1[@class="about-hero-title"]/br')->length, 'public About heading retains line break');
    $bio = '//li[b[1]="3 & years"]';
    expectSame('3 & years | <captain> | A <story> | fourth', $about->evaluate("string($bio)"), 'public bio preserves spaced pipes and escaped text');
    expectSame('<captain>', $about->evaluate("string($bio/b[2])"), 'public bio bolds the second part');
    expectSame(2, $about->query("$bio/b")->length, 'public bio bolds only the first two parts');
    expectSame(1, $public['/work']->query('//p[@class="subhead" and .="Fixture work heading"]')->length, 'public Work subheading');
    $dial = '//a[starts-with(@href, "tel:")]';
    expectSame('tel:' . $phone, $public['/contact']->evaluate("string($dial/@href)"), 'public Contact phone attribute');
    expectSame(true, str_ends_with($public['/contact']->evaluate("string($dial)"), $phone), 'public Contact phone text');
    expectSame(0, $public['/contact']->query("$dial/@data-fixture")->length, 'phone cannot add an HTML attribute');
    echo "PASS signed-in save, redirect, and edited copy across all six public routes\n";

    $previous = file_get_contents($contentPath);
    $invalidCsrf = ['action' => 'save', 'csrf' => 'invalid', 'f' => ['home.hero.heading' => '']];
    [$status, , $body] = request($admin, $invalidCsrf, $cookie);
    expectSame(200, $status, 'CSRF rejection redisplays form');
    $rejected = pageDom($body);
    expectSame('Your session expired. Please try again.', $rejected->evaluate('string(//div[@class="flash err"])'), 'CSRF error');
    expectSame('', formFields($rejected)['f']['home.hero.heading'], 'redisplay keeps submitted empty field');
    expectSame($phone, formFields($rejected)['f']['contact.phone'], 'redisplay uses saved value for missing field');
    expectSame($previous, file_get_contents($contentPath), 'CSRF rejection does not write content');

    unlink($contentPath);
    mkdir($contentPath);
    $overLimit = str_repeat('é', 2001);
    $failed = ['action' => 'save', 'csrf' => $current['csrf'], 'f' => [
        'home.hero.heading' => $overLimit,
        'about.hero.heading' => "\r\n  Line <one>\r\nLine & two  ",
        'contact.phone' => ['malformed'],
    ]];
    [$status, , $body] = request($admin, $failed, $cookie);
    expectSame(200, $status, 'failed replacement redisplays form');
    $error = pageDom($body);
    expectSame('Could not save changes (file permissions?).', $error->evaluate('string(//div[@class="flash err"])'), 'save error');
    $redisplayed = formFields($error)['f'];
    expectSame($overLimit, $redisplayed['home.hero.heading'], 'failed save redisplays over-limit value');
    expectSame('484.574.1444', $redisplayed['contact.phone'], 'failed save treats malformed field as missing');
    expectSame("\n  Line <one>\nLine & two  ", $redisplayed['about.hero.heading'], 'failed save normalizes CRLF and preserves the leading newline and spaces');
    expectSame('Glorifying God and Experiencing Joy Through Sports', $redisplayed['shared.footer.tagline'], 'failed save uses default for missing field');
    rmdir($contentPath);
    echo "PASS authorization, CSRF, and failed-save redisplay\n";

    file_put_contents($contentPath, $previous);
    $current['f'] = array_fill_keys(array_keys($current['f']), '');
    [$status, $headers] = request($admin, $current, $cookie);
    expectSame(302, $status, 'restoration redirects');
    [, , $body] = request($url . $headers['location'], null, $cookie);
    expectSame('Deepening coach impact', formFields(pageDom($body))['f']['home.hero.heading'], 'restored editor heading');
    [, , $body] = request("$url/");
    expectSame('Deepening coach impact', pageDom($body)->evaluate('string(//h1)'), 'restored public heading');
    [, , $body] = request("$url/contact");
    expectSame('tel:484.574.1444', pageDom($body)->evaluate("string($dial/@href)"), 'restored Contact phone attribute');
    echo "PASS restored defaults in the Content Editor and public pages\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $paths = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($paths as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($fixture);
}
