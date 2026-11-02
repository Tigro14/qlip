<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Ecriture STL ASCII (un `solid` par volume).
 */
final class StlWriter
{
    /**
     * @param list<array{name: string, mesh: Mesh}> $volumes
     */
    public static function write(array $volumes): string
    {
        $out = '';

        foreach ($volumes as $volume) {
            // Le nom est un seul jeton dans un STL ASCII : un retour a la ligne dans le
            // nom casserait le fichier. On le normalise, meme si Badge::build fournit
            // des noms simples.
            $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $volume['name']);

            if ($name === '') {
                $name = 'volume';
            }

            $out .= "solid {$name}\n";

            foreach ($volume['mesh']->triangles as $triangle) {
                $normal = Mesh::normal($triangle);

                $out .= sprintf(
                    "facet normal %.6f %.6f %.6f\n  outer loop\n",
                    $normal[0],
                    $normal[1],
                    $normal[2]
                );

                foreach ($triangle as $point) {
                    $out .= sprintf(
                        "    vertex %.6f %.6f %.6f\n",
                        $point[0],
                        $point[1],
                        $point[2]
                    );
                }

                $out .= "  endloop\nendfacet\n";
            }

            $out .= "endsolid {$name}\n";
        }

        return $out;
    }

    /**
     * STL binaire : meme contenu, 84 octets d'en-tete + 50 octets par facet.
     *
     * @param list<array{name: string, mesh: Mesh}> $volumes
     */
    public static function writeBinary(array $volumes): string
    {
        $count = 0;
        foreach ($volumes as $volume) {
            $count += $volume['mesh']->count();
        }

        $header = str_pad('Qlip keychain badge', 80, "\0");
        $out = substr($header, 0, 80) . pack('V', $count);

        foreach ($volumes as $volume) {
            foreach ($volume['mesh']->triangles as $triangle) {
                $normal = Mesh::normal($triangle);
                $out .= pack('f3', $normal[0], $normal[1], $normal[2]);

                foreach ($triangle as $point) {
                    $out .= pack('f3', $point[0], $point[1], $point[2]);
                }

                $out .= pack('v', 0);
            }
        }

        return $out;
    }
}
