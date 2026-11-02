<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Qlip\Qr\QrCode;
use Qlip\Stl\Badge;
use Qlip\Stl\Design;
use Qlip\Stl\StlWriter;
use Qlip\Stl\ThreeMfWriter;

$out = getenv('QLIP_OUT') ?: '/tmp/opencode/badge';

$scenarios = [
    'engrave_mono' => ['style' => 'engraved', 'bicolor' => false],
    'relief_mono' => ['style' => 'raised', 'bicolor' => false],
    'bicolor' => ['style' => 'raised', 'bicolor' => true],
    'bicolor_engrave' => ['style' => 'engraved', 'bicolor' => true],
    'petit' => ['style' => 'engraved', 'bicolor' => false, 'width' => 24.0, 'height' => 30.0, 'thickness' => 2.0, 'depth' => 0.5],
];

$report = [];

foreach ($scenarios as $name => $values) {
    $design = Design::fromArray($values);
    $qr = QrCode::encode('https://qlip.test/p/ab12cd', 'M');

    $volumes = Badge::build($qr, $design);

    $entry = ['scenario' => $name, 'modules' => $qr->size, 'volumes' => []];

    foreach ($volumes as $volume) {
        $problems = $volume['mesh']->checkWatertight();
        $bounds = $volume['mesh']->bounds();

        $entry['volumes'][] = [
            'name' => $volume['name'],
            'triangles' => $volume['mesh']->count(),
            'volume_mm3' => round($volume['mesh']->signedVolume(), 3),
            'bounds' => array_map(static fn (float $v): float => round($v, 3), $bounds),
            'problems' => array_slice($problems, 0, 5),
        ];
    }

    file_put_contents("{$out}/{$name}.stl", StlWriter::write($volumes));
    file_put_contents("{$out}/{$name}.3mf", ThreeMfWriter::write($volumes, [
        'title' => 'Badge Qlip ' . $name,
        'designer' => 'Qlip',
    ]));

    $report[] = $entry;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
