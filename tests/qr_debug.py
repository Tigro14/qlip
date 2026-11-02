#!/usr/bin/env python3
"""Compare une matrice PHP avec la reference `qrcode` (masque force) et localise les ecarts."""
import json
import os
import subprocess
import sys

import qrcode
import qrcode.util as u

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("QLIP_PHP", "/tmp/opencode/php")
JSON = os.environ.get("QLIP_QR_JSON", "/tmp/opencode/php_qr.json")

LEVELS = {
    "L": qrcode.constants.ERROR_CORRECT_L,
    "M": qrcode.constants.ERROR_CORRECT_M,
    "Q": qrcode.constants.ERROR_CORRECT_Q,
    "H": qrcode.constants.ERROR_CORRECT_H,
}

case = json.loads(open(JSON).readline())
level = LEVELS[case["level"]]
n = case["version"] * 4 + 17
mask = case["mask"]

ref = qrcode.QRCode(version=case["version"], error_correction=level, mask_pattern=mask)
ref.add_data(case["text"], optimize=0)
ref.data_cache = u.create_data(case["version"], level, ref.data_list)
ref.modules_count = n
ref.modules = [[None] * n for _ in range(n)]
ref.setup_position_probe_pattern(0, 0)
ref.setup_position_probe_pattern(n - 7, 0)
ref.setup_position_probe_pattern(0, n - 7)
ref.setup_timing_pattern()
ref.setup_position_adjust_pattern()
ref.setup_type_info(False, mask)
if case["version"] >= 7:
    ref.setup_type_number(False)

data_cells = sum(1 for r in range(n) for c in range(n) if ref.modules[r][c] is None)
ref.map_data(ref.data_cache, mask)

# Grille PHP : '.' = module fonctionnel (non encore rempli) sinon module de donnee.
php_lines = subprocess.run(
    [PHP, "-r", f'''
require "src/autoload.php";
use Qlip\\Qr\\{{QrCode, MatrixBuilder}};
$t = {json.dumps(case["text"])};
$m = new ReflectionMethod("Qlip\\Qr\\QrCode", "buildCodewords");
$cw = $m->invoke(null, $t, {json.dumps(case["mode"])}, {case["version"]}, {json.dumps(case["level"])});
foreach (MatrixBuilder::build({case["version"]}, {json.dumps(case["level"])}, {mask}, $cw) as $row) {{
    echo implode("", array_map(fn($v) => $v === null ? "." : ($v ? "1" : "0"), $row)), "\\n";
}}
'''],
    cwd=ROOT, capture_output=True, text=True, check=True,
).stdout.splitlines()

ref_lines = ["".join("." if x is None else ("1" if x else "0") for x in row) for row in ref.modules]

data_cells = sum(1 for r in range(n) for c in range(n) if ref.modules[r][c] is not None)
print("codewords:", len(ref.data_cache), "bits:", 8 * len(ref.data_cache), "cells donnees:", data_cells)

diffs = [(r, c, ref_lines[r][c], php_lines[r][c]) for r in range(n) for c in range(n) if ref_lines[r][c] != php_lines[r][c]]
print("ecarts:", len(diffs))
for d in diffs[:20]:
    print("  ", d)
