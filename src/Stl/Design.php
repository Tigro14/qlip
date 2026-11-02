<?php

declare(strict_types=1);

namespace Qlip\Stl;

/**
 * Parametres d'un badge porte-cles Qlip (dimensions en millimetres).
 */
final class Design
{
    public const STYLE_ENGRAVED = 'engraved';
    public const STYLE_RAISED = 'raised';

    public float $width = 34.0;
    public float $height = 40.0;
    public float $thickness = 3.0;
    public float $depth = 0.8;
    public float $cornerRadius = 4.0;
    public float $holeRadius = 2.5;
    public float $band = 5.0;      // distance entre le bord haut et le centre du trou
    public float $margin = 3.5;

    public string $style = self::STYLE_ENGRAVED;
    public bool $bicolor = false;

    public string $colorBase = '1A1A1A';
    public string $colorInk = 'FFFFFF';

    public static function fromArray(array $values): self
    {
        $design = new self();

        foreach (['width', 'height', 'thickness', 'depth', 'cornerRadius', 'holeRadius', 'band', 'margin'] as $key) {
            if (isset($values[$key])) {
                $design->$key = (float) $values[$key];
            }
        }

        if (isset($values['style'])) {
            $design->style = $values['style'] === self::STYLE_RAISED ? self::STYLE_RAISED : self::STYLE_ENGRAVED;
        }

        $design->bicolor = (bool) ($values['bicolor'] ?? false);

        foreach (['colorBase', 'colorInk'] as $key) {
            if (isset($values[$key])) {
                $design->$key = strtoupper(ltrim((string) $values[$key], '#'));
            }
        }

        $design->depth = max(0.4, min(2.0, $design->depth));
        $design->thickness = max(1.0, min(6.0, $design->thickness));
        $design->holeRadius = max(1.0, min(5.0, $design->holeRadius));
        $design->width = max(20.0, min(60.0, $design->width));
        $design->height = max(20.0, min(70.0, $design->height));

        return $design;
    }

    /** Taille d'un module QR qui tient dans la plaque. */
    public function moduleSize(int $qrSize): float
    {
        $availableWidth = $this->width - 2 * $this->margin;
        $availableHeight = $this->height - $this->margin - $this->band - $this->holeRadius;

        $module = min($availableWidth, $availableHeight) / max(1, $qrSize);

        return max(0.6, min(3.0, $module));
    }

    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'thickness' => $this->thickness,
            'depth' => $this->depth,
            'cornerRadius' => $this->cornerRadius,
            'holeRadius' => $this->holeRadius,
            'band' => $this->band,
            'margin' => $this->margin,
            'style' => $this->style,
            'bicolor' => $this->bicolor,
            'colorBase' => $this->colorBase,
            'colorInk' => $this->colorInk,
        ];
    }
}
