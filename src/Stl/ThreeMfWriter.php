<?php

declare(strict_types=1);

namespace Qlip\Stl;

use Qlip\Support\ZipWriter;

/**
 * Conteneur .3MF : un objet par volume, avec un groupe de couleurs pour les
 * slicers multicolores (Bambu/Lanza/Prusa).
 */
final class ThreeMfWriter
{
    private const CORE_NAMESPACE = 'http://schemas.microsoft.com/3dmanufacturing/core/2015/02';
    private const MATERIAL_NAMESPACE = 'http://schemas.microsoft.com/3dmanufacturing/material/2015/02';

    /**
     * @param list<array{name: string, color: string, mesh: Mesh}> $volumes
     */
    public static function write(array $volumes, array $meta = []): string
    {
        $zip = new ZipWriter();

        $zip->add('[Content_Types].xml', self::contentTypes());
        $zip->add('_rels/.rels', self::relationships());
        $zip->add('docProps/core.xml', self::coreProperties($meta));
        $zip->add('3D/3dmodel.model', self::model($volumes, $meta));

        return $zip->contents();
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="model" ContentType="application/vnd.ms-3mfdocument"/>'
            . '<Override PartName="/3D/3dmodel.model" ContentType="application/vnd.ms-3mfdocument"/>'
            . '</Types>';
    }

    private static function relationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel" Target="/3D/3dmodel.model"/>'
            . '</Relationships>';
    }

    private static function coreProperties(array $meta): string
    {
        $title = htmlspecialchars($meta['title'] ?? 'Qlip keychain badge', ENT_QUOTES, 'UTF-8');
        $designer = htmlspecialchars($meta['designer'] ?? 'Qlip', ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<coreProperties xmlns="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title>'
            . '<dc:creator>' . $designer . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . ($meta['created'] ?? '2026-01-01T00:00:00Z') . '</dcterms:created>'
            . '</coreProperties>';
    }

    private static function model(array $volumes, array $meta): string
    {
        $colors = [];
        foreach ($volumes as $volume) {
            $color = strtoupper(ltrim($volume['color'], '#'));
            if (!in_array($color, $colors, true)) {
                $colors[] = $color;
            }
        }

        $out = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<model unit="millimeter" xml:lang="fr-FR" '
            . 'xmlns="' . self::CORE_NAMESPACE . '" xmlns:m="' . self::MATERIAL_NAMESPACE . '">'
            . '<metadata name="Application">Qlip</metadata>'
            . '<metadata name="Title">' . htmlspecialchars($meta['title'] ?? 'Qlip keychain badge', ENT_QUOTES, 'UTF-8') . '</metadata>'
            . '<resources>';

        if ($colors !== []) {
            // Les couleurs appartiennent a l'extension material : elles doivent
            // etre dans le namespace de l'extension, pas dans le namespace par
            // defaut (core). Les attributs pid et pindex restent dans core.
            $out .= '<m:colors id="1" name="Qlip">';
            foreach ($colors as $index => $color) {
                $out .= '<m:color id="' . $index . '" value="#' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"/>';
            }
            $out .= '</m:colors>';
        }

        $resourceId = 2;

        foreach ($volumes as $volume) {
            $colorIndex = array_search(strtoupper(ltrim($volume['color'], '#')), $colors, true);

            $out .= '<object id="' . $resourceId . '" type="model" name="'
                . htmlspecialchars($volume['name'], ENT_QUOTES, 'UTF-8') . '"'
                . ($colorIndex === false ? '' : ' pid="1" pindex="' . $colorIndex . '"')
                . '>';

            $out .= '<mesh>' . self::mesh($volume['mesh']);
            $out .= '</object>';

            $resourceId++;
        }

        $out .= '</resources><build>';

        for ($i = 2; $i < $resourceId; $i++) {
            $out .= '<item objectid="' . $i . '"/>';
        }

        return $out . '</build></model>';
    }

    private static function mesh(Mesh $mesh): string
    {
        $vertices = [];
        $indices = [];

        foreach ($mesh->triangles as $triangle) {
            $triangleIndices = [];

            foreach ($triangle as $point) {
                $key = sprintf('%.4f,%.4f,%.4f', $point[0], $point[1], $point[2]);

                if (!isset($vertices[$key])) {
                    $vertices[$key] = [count($vertices), $point];
                }

                $triangleIndices[] = $vertices[$key][0];
            }

            $indices[] = $triangleIndices;
        }

        $out = '<vertices>';

        foreach ($vertices as $vertex) {
            $out .= sprintf(
                '<vertex x="%.4f" y="%.4f" z="%.4f"/>',
                $vertex[1][0],
                $vertex[1][1],
                $vertex[1][2]
            );
        }

        $out .= '</vertices><triangles>';

        foreach ($indices as $triangle) {
            $out .= sprintf(
                '<triangle v1="%d" v2="%d" v3="%d"/>',
                $triangle[0],
                $triangle[1],
                $triangle[2]
            );
        }

        return $out . '</triangles></mesh>';
    }
}
