#!/usr/bin/env python3
"""Test elargi : textes aleatoires, tous les niveaux, rendus PNG/SVG decodes par zxing-cpp."""
import json
import os
import random
import string
import subprocess
import sys

import zxingcpp
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("QLIP_PHP", "/tmp/opencode/php")

random.seed(20261102)

texts = []
for _ in range(40):
    length = random.randint(1, 120)
    texts.append("".join(random.choice(string.printable.strip()) for _ in range(length)))
texts += [
    "0", "00", "8675309", "HELLO WORLD", "https://qlip.test/p/ab12cd",
    "https://qlip.test/p/qlip-demo-0001", "contact", "Qlip", "porte-cles Qlip",
    "A B C D E F G H I J K L M N O P Q R S T U V W X Y Z $%*+-./:",
    str(random.getrandbits(64)),
    "https://qlip.test/p/sacré-é-à",
    "".join(random.choice("0123456789") for _ in range(500)),
    "".join(random.choice(string.ascii_letters) for _ in range(900)),
    "".join(random.choice("ABC") for _ in range(1800)),
]

payload = "\n".join(texts)
out = subprocess.run(
    [PHP, "tests/qr_fuzz_dump.php"], cwd=ROOT, input=payload, capture_output=True, text=True, check=True
).stdout

levels = {"L": 0, "M": 0, "Q": 0, "H": 0}
decoded = 0
errors = []

def render(matrix, quiet=4, scale=3):
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

for line in out.splitlines():
    if not line.strip():
        continue
    case = json.loads(line)
    m = [[bool(int(ch)) for ch in row] for row in case["matrix"]]
    result = zxingcpp.read_barcode(render(m), formats=zxingcpp.BarcodeFormat.QRCode)
    if not result or result.text != case["text"]:
        errors.append((case["text"][:40], case["level"], case["version"], case["mask"]))
    else:
        decoded += 1

print(f"QR encodes et re-decodes : {decoded}")
print(f"echecs                   : {len(errors)}")
for e in errors[:10]:
    print("  ECART", e)

sys.exit(1 if errors else 0)
