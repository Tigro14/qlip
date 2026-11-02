#!/usr/bin/env python3
"""Contre-verifie l'encodeur QR PHP avec deux implementations de reference :
`qrcode` (Python) et `zxing-cpp` (decodage : c'est la preuve que la matrice est valide).

Lance : php tests/qr_dump.php > /tmp/opencode/php_qr.json puis ce script.
"""
import json
import os
import subprocess
import sys

import qrcode
import qrcode.util
import zxingcpp
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("QLIP_PHP", "/tmp/opencode/php")
JSON = os.environ.get("QLIP_QR_JSON", "/tmp/opencode/php_qr.json")

LEVELS = {
    "L": qrcode.constants.ERROR_CORRECT_L,
    "M": qrcode.constants.ERROR_CORRECT_M,
    "Q": qrcode.constants.ERROR_CORRECT_Q,
    "H": qrcode.constants.ERROR_CORRECT_H,
}


def render(matrix, quiet=4, scale=4):
    n = len(matrix)
    img = Image.new("RGB", ((n + 2 * quiet) * scale, (n + 2 * quiet) * scale), (255, 255, 255))
    px = img.load()
    for r in range(n):
        for c in range(n):
            if matrix[r][c]:
                for dr in range(scale):
                    for dc in range(scale):
                        px[(c + quiet) * scale + dc, (r + quiet) * scale + dr] = (0, 0, 0)
    return img


subprocess.run([PHP, "tests/qr_dump.php"], cwd=ROOT, check=True, stdout=open(JSON, "w"))

cases = [json.loads(line) for line in open(JSON)]

version_errors = []
codeword_errors = []
matrix_errors = []
decode_errors = []
mask_diffs = 0

for case in cases:
    level = LEVELS[case["level"]]

    ref = qrcode.QRCode(version=None, error_correction=level)
    ref.add_data(case["text"], optimize=0)
    ref.make()
    best_mask = ref.best_mask_pattern()
    if ref.version != case["version"]:
        version_errors.append((case["text"], case["level"], case["version"], ref.version))
    if best_mask != case["mask"]:
        mask_diffs += 1

    # Reference avec le masque choisi par PHP : la comparaison de matrice a du sens
    # (make() appellera best_mask_pattern() sinon, donc on force makeImpl).
    ref2 = qrcode.QRCode(version=case["version"], error_correction=level, mask_pattern=case["mask"])
    ref2.add_data(case["text"], optimize=0)
    ref2.data_cache = qrcode.util.create_data(case["version"], level, ref2.data_list)
    ref2.modules_count = case["version"] * 4 + 17
    ref2.modules = [[None] * ref2.modules_count for _ in range(ref2.modules_count)]
    ref2.setup_position_probe_pattern(0, 0)
    ref2.setup_position_probe_pattern(ref2.modules_count - 7, 0)
    ref2.setup_position_probe_pattern(0, ref2.modules_count - 7)
    ref2.setup_position_adjust_pattern()
    ref2.setup_timing_pattern()
    ref2.setup_type_info(False, case["mask"])
    if case["version"] >= 7:
        ref2.setup_type_number(False)
    ref2.map_data(ref2.data_cache, case["mask"])

    if ref.mask_pattern != case["mask"]:
        mask_diffs += 1

    matrix_ref = [[bool(x) for x in row] for row in ref2.modules]
    matrix_php = [[bool(int(ch)) for ch in row] for row in case["matrix"]]

    if matrix_ref != matrix_php:
        first = next(
            (r, c, int(matrix_ref[r][c]), int(matrix_php[r][c]))
            for r in range(len(matrix_ref))
            for c in range(len(matrix_ref))
            if matrix_ref[r][c] != matrix_php[r][c]
        )
        matrix_errors.append((case["text"], case["level"], case["version"], first))

    # Preuve finale : zxing-cpp doit relire la matrice produite par PHP.
    result = zxingcpp.read_barcode(render(matrix_php), formats=zxingcpp.BarcodeFormat.QRCode)
    if not result or result.text != case["text"]:
        decode_errors.append((case["text"], case["level"], case["version"], case["mask"], result.text if result else None))

print(f"cas compares            : {len(cases)}")
print(f"ecarts de version       : {len(version_errors)}")
print(f"ecarts de masque        : {mask_diffs} (tie-break de penalite, toleré)")
print(f"ecarts de matrice       : {len(matrix_errors)}")
print(f"echecs de decodage zxing: {len(decode_errors)}")

for label, items in (("VERSION", version_errors), ("MATRICE", matrix_errors), ("DECODAGE", decode_errors)):
    for item in items[:8]:
        print(f"  {label} {item}")

sys.exit(1 if (version_errors or matrix_errors or decode_errors) else 0)
