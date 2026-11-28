<?php

// Run with: php tests/editable-page-text.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../private/content.php';

function expectSame($expected, $actual, string $context): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "$context\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function editorFieldsByKey(EditablePageText $content, mixed $submitted = null): array
{
    $byKey = [];
    foreach ($content->editorFields($submitted) as $group) {
        foreach ($group['fields'] as $field) {
            $byKey[$field['key']] = $field;
        }
    }
    return $byKey;
}

$fixture = exec('mktemp -d', $output, $status);
if ($status !== 0 || $fixture === '') {
    throw new RuntimeException('Could not create the editable page text fixture directory');
}
$path = "$fixture/content.json";

try {
    $content = new EditablePageText($path);
    $groups = $content->editorFields();
    expectSame(
        ['Home page', 'About page', 'About — John’s background', 'Work page', 'Contact page', 'Footer (all pages)'],
        array_column($groups, 'label'),
        'editor group order'
    );
    $fields = editorFieldsByKey($content);
    expectSame([
        'home.hero.heading', 'home.hero.subhead', 'home.quote.text',
        'about.hero.heading', 'about.hero.subhead', 'about.founder.heading', 'about.founder.subhead',
        'about.bio.christ', 'about.bio.family', 'about.bio.coach', 'about.bio.athlete', 'about.bio.younglife',
        'about.bio.corporate', 'about.bio.admin', 'about.edu.degree1', 'about.edu.school1', 'about.edu.degree2', 'about.edu.school2',
        'work.hero.heading', 'work.hero.subhead', 'work.seminars.heading', 'work.seminars.subhead', 'work.consulting.heading', 'work.consulting.subhead',
        'contact.hero.heading', 'contact.hero.subhead', 'contact.connect.heading', 'contact.connect.quote', 'contact.connect.cite',
        'contact.phone', 'contact.contactjohn.heading',
        'shared.footer.tagline',
    ], array_keys($fields), 'editor field order');
    expectSame([
        'key' => 'about.hero.heading',
        'label' => 'Hero heading',
        'help' => 'Each line break starts a new line on the page.',
        'type' => 'multiline',
        'limit' => 2000,
        'value' => "Game day\nis a\ncall to worship",
    ], $fields['about.hero.heading'], 'textarea projection');
    expectSame([
        'key' => 'home.hero.heading',
        'label' => 'Hero heading',
        'help' => null,
        'type' => 'text',
        'limit' => 2000,
        'value' => 'Deepening coach impact',
    ], $fields['home.hero.heading'], 'text input projection with missing overrides file');
    echo "PASS grouped editor fields\n";

    foreach (['{', '"not a field map"'] as $document) {
        file_put_contents($path, $document);
        expectSame('Deepening coach impact', (new EditablePageText($path))->text('home.hero.heading'), 'unusable JSON document falls back');
    }
    $original = [
        'home.hero.heading' => '',
        'home.hero.subhead' => false,
        'home.quote.text' => ['invalid'],
        'about.founder.heading' => 5,
        'work.hero.subhead' => null,
        'contact.phone' => '   ',
        'about.hero.heading' => " First\r\nSecond\rThird\n ",
        'shared.footer.tagline' => 'Old tagline',
        'unknown' => 'old',
    ];
    file_put_contents($path, json_encode($original, JSON_THROW_ON_ERROR));
    $content = new EditablePageText($path);
    $fields = editorFieldsByKey($content);
    expectSame('Deepening coach impact', $fields['home.hero.heading']['value'], 'empty saved value falls back');
    expectSame('Through one-on-one mentoring, consulting, and seminars', $fields['home.hero.subhead']['value'], 'boolean saved value falls back');
    expectSame('To become holy is rather like joining a secret society.' . "\n" . 'To put it at the very lowest, it must be great fun.', $fields['home.quote.text']['value'], 'array saved value falls back');
    expectSame('John Levis, Founder', $fields['about.founder.heading']['value'], 'numeric saved value falls back');
    expectSame('And winning and losing do matter', $fields['work.hero.subhead']['value'], 'null saved value falls back');
    expectSame('   ', $content->text('contact.phone'), 'legacy nonempty whitespace loads verbatim');
    expectSame(" First\r\nSecond\rThird\n ", $fields['about.hero.heading']['value'], 'legacy newlines and spaces load verbatim');
    expectSame(" First<br>\r\nSecond<br>\rThird<br>\n ", $content->lines('about.hero.heading'), 'legacy line breaks retain nl2br formatting');
    expectSame('', $content->text('unknown'), 'unregistered saved key is not editable page text');
    echo "PASS saved value fallback and legacy formatting\n";

    $limitInput = " \r\n" . str_repeat('é', 1997) . 'Zdiscard';
    $phone = '555.0100" data-fixture="phone';
    $submitted = [
        'home.hero.heading' => $limitInput,
        'home.hero.subhead' => "   \r\n\t",
        'about.hero.heading' => "Game day\r\nis a\r\ncall to worship",
        'about.bio.christ' => "  3 & years | <captain> | A <story> | fourth  ",
        'about.founder.heading' => ['invalid'],
        'work.hero.heading' => "\r\n  First <line>\r\nSecond & final  \r\n",
        'contact.phone' => $phone,
        'shared.footer.tagline' => '  Café / sport & rest  ',
        'unknown' => 'new',
    ];
    $fields = editorFieldsByKey($content, $submitted + ['contact.hero.heading' => '']);
    expectSame(" \n" . str_repeat('é', 1997) . 'Zdiscard', $fields['home.hero.heading']['value'], 'redisplay normalizes CRLF and preserves length and whitespace');
    expectSame('', $fields['contact.hero.heading']['value'], 'redisplay preserves empty submissions');
    expectSame('John Levis, Founder', $fields['about.founder.heading']['value'], 'nonstrings fall back on redisplay');
    expectSame('Old tagline', editorFieldsByKey($content, 'invalid map')['shared.footer.tagline']['value'], 'malformed field map falls back');
    expectSame('   ', editorFieldsByKey($content, [])['contact.phone']['value'], 'missing submitted field uses saved value');

    expectSame(true, $content->save($submitted), 'successful save');
    $encoded = file_get_contents($path);
    expectSame([
        'home.hero.heading' => str_repeat('é', 1997) . 'Z',
        'about.bio.christ' => '3 & years | <captain> | A <story> | fourth',
        'work.hero.heading' => "First <line>\nSecond & final",
        'contact.phone' => $phone,
        'shared.footer.tagline' => 'Café / sport & rest',
    ], json_decode($encoded, true, 512, JSON_THROW_ON_ERROR), 'save normalizes, caps by UTF-8 characters, trims, and replaces the full override document');
    expectSame(true, str_contains($encoded, "\n    \"shared.footer.tagline\": \"Café / sport & rest\"\n"), 'legacy JSON encoding flags');
    expectSame('Café / sport &amp; rest', $content->text('shared.footer.tagline'), 'same instance reflects successful save');
    $fresh = new EditablePageText($path);
    expectSame($content->editorFields(), $fresh->editorFields(), 'fresh read after save');
    expectSame('555.0100&quot; data-fixture=&quot;phone', $fresh->text('contact.phone'), 'text escapes for attributes');
    expectSame("First &lt;line&gt;<br>\nSecond &amp; final", $fresh->lines('work.hero.heading'), 'lines escape before converting newlines');
    expectSame('<b>3 &amp; years</b> | <b>&lt;captain&gt;</b> | A &lt;story&gt; | fourth', $fresh->bio('about.bio.christ'), 'bio escapes and bolds the first two spaced-pipe parts');
    echo "PASS save, reload, projection, and public formatting\n";

    expectSame(false, $content->save(['shared.footer.tagline' => "\xFF"]), 'encoding failure leaves stored content intact');
    expectSame($encoded, file_get_contents($path), 'encoding failure retains the previous document');
    unlink($path);
    mkdir($path);
    expectSame(false, $content->save(['shared.footer.tagline' => 'Failed replacement']), 'atomic replacement fails when the destination is a directory');
    expectSame('Café / sport &amp; rest', $content->text('shared.footer.tagline'), 'failed save retains the loaded snapshot');
    expectSame([], glob("$path.tmp*"), 'failed replacement cleans up its temporary write');
    rmdir($path);

    expectSame(true, $fresh->save(['home.hero.heading' => '', 'shared.footer.tagline' => 'Glorifying God and Experiencing Joy Through Sports']), 'restore defaults');
    expectSame('[]', file_get_contents($path), 'restoration writes an empty flat overrides document');
    expectSame('Deepening coach impact', (new EditablePageText($path))->text('home.hero.heading'), 'restored default after reload');
    echo "PASS failed save and default restoration\n";
} finally {
    if (is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        rmdir($path);
    }
    rmdir($fixture);
}
