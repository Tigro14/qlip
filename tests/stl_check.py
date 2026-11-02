#!/usr/bin/env python3
"""Controle independent des fichiers generes par tests/stl_dump.php.

Pour chaque scenario :
  - STL ASCII : chaque solid doit etre ferme et orientable (chaque arete
    parcourue autant de fois dans un sens que dans l'autre), sans triangle
    degenere, avec un volume positif. Les aretes utilisees deux fois dans le
    meme sens sont signalees comme contacts (faces confondes de deux volumes).
  - 3MF : zip valide, namespaces corrects, couleurs, unite mm, nombre de
    triangles identique au STL.

Usage : python3 tests/stl_check.py
"""

import os
import re
import sys
import zipfile

OUT = os.environ.get("QLIP_OUT", "/tmp/opencode/badge")
CORE = "http://schemas.microsoft.com/3dmanufacturing/core/2015/02"
MATERIAL = "http://schemas.microsoft.com/3dmanufacturing/material/2015/02"

EPS = 1e-9


def key(point):
    return (round(point[0], 6), round(point[1], 6), round(point[2], 6))


def parse_ascii_stl(text):
    solids = []
    current = None

    for line in text.splitlines():
        line = line.strip()

        if line.startswith("solid"):
            current = {"name": line[5:].strip(), "facets": []}
            solids.append(current)
            continue

        if line.startswith("endsolid"):
            current = None
            continue

        if not line:
            continue

        match = re.match(
            r"facet normal ([-\d.e+]+) ([-\d.e+]+) ([-\d.e+]+)", line
        )
        if match and current is not None:
            normal = tuple(float(value) for value in match.groups())
            current["facets"].append({"normal": normal, "points": []})
            continue

        match = re.match(r"vertex ([-\d.e+]+) ([-\d.e+]+) ([-\d.e+]+)", line)
        if match and current is not None and current["facets"]:
            current["facets"][-1]["points"].append(
                tuple(float(value) for value in match.groups())
            )

    return solids


def triangle_area(points):
    u = [points[1][i] - points[0][i] for i in range(3)]
    v = [points[2][i] - points[0][i] for i in range(3)]
    cross = [u[1] * v[2] - u[2] * v[1], u[2] * v[0] - u[0] * v[2], u[0] * v[1] - u[1] * v[0]]
    return 0.5 * (cross[0] ** 2 + cross[1] ** 2 + cross[2] ** 2) ** 0.5


def signed_volume(facets):
    total = 0.0

    for facet in facets:
        a, b, c = facet["points"]
        total += (
            a[0] * (b[1] * c[2] - b[2] * c[1])
            + b[0] * (c[1] * a[2] - c[2] * a[1])
            + c[0] * (a[1] * b[2] - a[2] * b[1])
        ) / 6.0

    return total


def check_solid(solid):
    problems = []
    contacts = []
    directed = {}

    for facet in solid["facets"]:
        points = facet["points"]

        if len(points) != 3:
            problems.append("facet avec %d sommets" % len(points))
            continue

        if triangle_area(points) <= EPS:
            problems.append("triangle degenere %s" % (points,))
            continue

        for i in range(3):
            a, b = points[i], points[(i + 1) % 3]
            directed.setdefault((key(a), key(b)), 0)
            directed[(key(a), key(b))] += 1

        normal = facet["normal"]
        u = [points[1][i] - points[0][i] for i in range(3)]
        v = [points[2][i] - points[0][i] for i in range(3)]
        cross = [u[1] * v[2] - u[2] * v[1], u[2] * v[0] - u[0] * v[2], u[0] * v[1] - u[1] * v[0]]

        if sum(normal) == 0.0:
            continue  # STL ecrit sans normale : le sens est celui du triangle

        dot = sum(normal[i] * cross[i] for i in range(3))
        if dot < -1e-9:
            problems.append("normale contraire au sens des sommets %s" % (points,))

    for (a, b), count in directed.items():
        reverse = directed.get((key(b), key(a)), 0)

        if count > 1 and reverse == count:
            contacts.append("contact %s|%s x%d" % (a, b, count))
        elif count != reverse:
            problems.append(
                "arete %s|%s : %d fois dans ce sens, %d en sens inverse" % (a, b, count, reverse)
            )

    return problems, contacts, signed_volume(solid["facets"])


def check_3mf(path, expected_volumes):
    problems = []

    try:
        archive = zipfile.ZipFile(path)
    except Exception as error:
        return ["zip illisible : %s" % error]

    names = set(archive.namelist())

    for required in ("[Content_Types].xml", "_rels/.rels", "3D/3dmodel.model"):
        if required not in names:
            problems.append("entree manquante : %s" % required)

    if problems:
        return problems

    if archive.testzip() is not None:
        problems.append("entree corrompue")

    model = archive.read("3D/3dmodel.model").decode("utf-8")

    if CORE not in model:
        problems.append("namespace core manquant")

    units = re.search(r'unit="([^"]+)"', model)
    if not units or units.group(1) != "millimeter":
        problems.append("unites incorrectes")

    objects = re.findall(r"<object[^>]*>.*?</object>", model, re.S)
    colors = re.search(r"<(?:m:)?colors[^>]*>", model)

    if not objects:
        problems.append("aucun object")

    has_pid = any("pid=" in obj for obj in objects)
    if colors is None and has_pid:
        problems.append("pid employe sans <colors>")

    if colors is not None:
        if MATERIAL not in model:
            problems.append("namespace material manquant")
        elif not colors.group(0).startswith("<m:colors"):
            problems.append("<colors> doit etre dans le namespace material (m:colors)")

    for index, obj in enumerate(objects):
        mesh = re.search(r"<mesh[^>]*>(.*?)</mesh>", obj, re.S)

        if mesh is None:
            problems.append("object %d sans mesh" % index)
            continue

        count = len(re.findall(r"<(?:m:)?triangle ", mesh.group(1)))

        if index < len(expected_volumes) and count != expected_volumes[index]:
            problems.append(
                "object %d : %d triangles, %d attendus" % (index, count, expected_volumes[index])
            )

    return problems


def main():
    failures = 0

    for name in sorted(os.listdir(OUT)):
        if not name.endswith(".stl"):
            continue

        scenario = name[:-4]
        stl_path = os.path.join(OUT, name)
        text = open(stl_path, encoding="utf-8").read()

        if not text.startswith("solid"):
            print("%s : pas du STL ASCII" % name)
            failures += 1
            continue

        solids = parse_ascii_stl(text)
        counts = []

        for solid in solids:
            problems, contacts, volume = check_solid(solid)
            counts.append(len(solid["facets"]))

            status = "ok" if not problems else "ERREUR"
            print("%s [%s] %d triangles, volume %.3f mm3 : %s" % (
                name, solid["name"], len(solid["facets"]), volume, status
            ))

            for problem in problems[:5]:
                print("   %s" % problem)
                failures += 1

            if volume <= 0.0:
                print("   volume negatif ou nul")
                failures += 1

            if contacts:
                print("   contacts : %d" % len(contacts))

        mf_path = os.path.join(OUT, scenario + ".3mf")

        if os.path.exists(mf_path):
            problems = check_3mf(mf_path, counts)
            print("%s : %s" % (scenario + ".3mf", "ok" if not problems else "ERREUR"))

            for problem in problems[:5]:
                print("   %s" % problem)
                failures += 1

    print("----")
    print("problemes : %d" % failures)
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
