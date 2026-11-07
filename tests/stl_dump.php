<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Qr\QrCode;
use Qlip\Stl\Badge;
use Qlip\Stl\Design;
use Qlip\Stl\Mesh;
use Qlip\Stl\PixelFont;
use Qlip\Stl\StlWriter;
use Qlip\Stl\TextLayout;
use Qlip\Stl\ThreeMfWriter;

$out = getenv('QLIP_OUT') ?: '/tmp/opencode/badge';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo $ok ? '  OK   ' : '  KO   ', $label, $detail !== '' ? ' : ' . $detail : '', PHP_EOL;
}

/** Niveaux de z rencontres dans le mesh, avec le nombre de triangles poses dessus. */
function levels(Mesh $mesh): array
{
    $levels = [];

    foreach ($mesh->triangles as $triangle) {
        $z = $triangle[0][2];

        foreach ($triangle as $point) {
            if (abs($point[2] - $z) > 1e-9) {
                $z = NAN; // triangle incline : pas un plan
                break;
            }
        }

        if (!is_nan($z)) {
            $key = sprintf('%.6f', $z);
            $levels[$key] = ($levels[$key] ?? 0) + 1;
        }
    }

    ksort($levels);

    return $levels;
}

/**
 * Carte attendue des pixels graves, mesuree sur le texte lu depuis le dos : le dos se
 * regarde a l envers de la plaque (x inverse, y conserve), donc le signe i tombe sur la
 * cellule miroir de la grille, a partir de la ligne la plus longue, et le haut du glyphe
 * comme la premiere ligne du texte du cote des v eleves. Reconstruite a partir de la
 * police, sans TextLayout.
 *
 * @param array{rows: list<string>, cells: array<string, array<string, mixed>>} $layout
 * @return array<string, bool>
 */
function expectedInk(array $layout): array
{
    $advance = PixelFont::WIDTH + TextLayout::GAP;
    $lineGap = PixelFont::HEIGHT + TextLayout::LINE_GAP;
    $lines = count($layout['rows']);
    $longest = 0;

    foreach ($layout['rows'] as $row) {
        $longest = max($longest, strlen($row));
    }

    $want = [];

    foreach ($layout['rows'] as $line => $text) {
        for ($i = 0; $i < strlen($text); $i++) {
            $glyph = PixelFont::glyph($text[$i]);

            for ($r = 0; $r < PixelFont::HEIGHT; $r++) {
                for ($c = 0; $c < PixelFont::WIDTH; $c++) {
                    if ($glyph[$r][$c] !== '#') {
                        continue;
                    }

                    $u = $advance * ($longest - 1 - $i) + (PixelFont::WIDTH - 1 - $c);
                    $v = ($lines - 1 - $line) * $lineGap + (PixelFont::HEIGHT - 1 - $r);

                    $want[$u . ',' . $v] = true;
                }
            }
        }
    }

    return $want;
}

$short = 'https://qlip.test/p/ab12cd';
$long = 'https://qlip.test/p/0123456789abcdef0123456789abcd';

$scenarios = [
    'engrave_mono' => ['style' => 'engraved', 'bicolor' => false],
    'sans_texte' => ['style' => 'engraved', 'bicolor' => false, 'engraveUrl' => false],
    'relief_mono' => ['style' => 'raised', 'bicolor' => false],
    'bicolor' => ['style' => 'raised', 'bicolor' => true],
    'bicolor_engrave' => ['style' => 'engraved', 'bicolor' => true],
    'petit' => ['style' => 'engraved', 'bicolor' => false, 'width' => 24.0, 'height' => 30.0, 'thickness' => 2.0, 'depth' => 0.5],
    'long' => ['style' => 'engraved', 'bicolor' => false, 'url' => $long],
    'etroit' => [
        'style' => 'engraved',
        'bicolor' => false,
        'width' => 22.0,
        'height' => 27.0,
        'thickness' => 1.5,
        'depth' => 0.5,
    ],
];

$report = [];
$volumes = [];

foreach ($scenarios as $name => $values) {
    $url = $values['url'] ?? $short;
    $design = Design::fromArray($values);
    $qr = QrCode::encode($url, 'M');

    $built = Badge::build($qr, $design, $url);

    $entry = [
        'scenario' => $name,
        'modules' => $qr->size,
        'engrave' => $design->engraveUrl,
        'text' => null,
        'pixel' => null,
        'volumes' => [],
    ];

    if ($design->engraveUrl) {
        $layout = TextLayout::build($url, $design);

        check("{$name} : texte grave", $layout !== null, $layout === null ? 'aucune mise en page' : '');

        if ($layout !== null) {
            $entry['text'] = $layout['text'];
            $entry['pixel'] = round($layout['pixel'], 3);
            $entry['rows'] = count($layout['rows']);

            check(
                "{$name} : le texte grave ne promet pas un protocole",
                !str_contains($layout['text'], '://') && $layout['text'] !== '',
                $layout['text']
            );
        }
    }

    foreach ($built as $volume) {
        $problems = $volume['mesh']->checkWatertight();
        $bounds = $volume['mesh']->bounds();
        $levelsOfMesh = levels($volume['mesh']);

        check(
            "{$name} [{$volume['name']}] ferme et orientable",
            $problems === [],
            implode(' | ', array_slice($problems, 0, 3))
        );

        // La gravure du dos est creuse : rien ne descend sous le dos, et le fond des
        // alveoles est un plan pose a la profondeur demande.
        check(
            "{$name} [{$volume['name']}] rien sous le dos",
            $bounds[2] >= -1e-9,
            sprintf('min z = %.4f', $bounds[2])
        );

        if ($design->engraveUrl && $volume['name'] !== 'ink') {
            $floor = sprintf('%.6f', $design->textDepth);

            check(
                "{$name} [{$volume['name']}] fonds d alveoles a {$design->textDepth} mm",
                ($levelsOfMesh[$floor] ?? 0) > 0,
                implode(', ', array_map(static fn (float $z): string => sprintf('%.3f', $z), array_keys($levelsOfMesh)))
            );
        }

        $volumes[$name][$volume['name']] = $volume['mesh']->signedVolume();

        $entry['volumes'][] = [
            'name' => $volume['name'],
            'triangles' => $volume['mesh']->count(),
            'volume_mm3' => round($volume['mesh']->signedVolume(), 3),
            'bounds' => array_map(static fn (float $v): float => round($v, 3), $bounds),
            'problems' => array_slice($problems, 0, 5),
        ];
    }

    file_put_contents("{$out}/{$name}.stl", StlWriter::write($built));
    file_put_contents("{$out}/{$name}.3mf", ThreeMfWriter::write($built, [
        'title' => 'Badge Qlip ' . $name,
        'designer' => 'Qlip',
    ]));

    $report[] = $entry;
}

// La gravure enleve de la matiere : c est ce qui la distingue d un relief colle dessous.
if (isset($volumes['engrave_mono']['badge'], $volumes['sans_texte']['badge'])) {
    $removed = $volumes['sans_texte']['badge'] - $volumes['engrave_mono']['badge'];

    check('la gravure du dos enleve de la matiere', $removed > 0.0, sprintf('%.3f mm3', $removed));
}

// Une plaque trop petite pour l adresse entiere la grave tronquee, avec le signe qui le dit.
$etroit = Design::fromArray($scenarios['etroit']);
$narrow = TextLayout::build($long, $etroit);

check(
    'texte tronque marque par un tilde',
    $narrow !== null && $narrow['truncated'] && str_ends_with($narrow['text'], '~'),
    $narrow === null ? 'aucune mise en page' : $narrow['text']
);

// Chaque pixel grave tombe sur la cellule miroir de la grille : le texte grave se lit a
// l endroit depuis le dos, glyphe par glyphe. Mesure une ligne, deux lignes, et une
// troncature.
$readable = [
    'gravure lisible depuis le dos' => TextLayout::build($short, Design::fromArray($scenarios['engrave_mono'])),
    'gravure lisible depuis le dos (tronquee)' => TextLayout::build($long, Design::fromArray($scenarios['long'])),
    'gravure lisible depuis le dos (etroite)' => $narrow,
];

foreach ($readable as $label => $map) {
    if ($map === null) {
        check($label, false, 'aucune mise en page');
        continue;
    }

    $want = expectedInk($map);
    $ok = $want !== [];

    foreach ($map['cells'] as $key => $cell) {
        if ($cell['engraved'] !== isset($want[$key])) {
            $ok = false;
            break;
        }
    }

    check($label, $ok, sprintf('%d pixels graves', count($want)));
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($failures === 0 ? 0 : 1);
