#!/usr/bin/env python3
"""Build an unpublished, deterministic plugin ZIP and its SHA-256 checksum."""
import hashlib
from pathlib import Path
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = "elementor-podcast-manager"


def build(output):
    tracked = subprocess.check_output(
        ["git", "ls-files", "-z", "--", "admin", "assets", "includes", "languages"], cwd=ROOT
    ).decode().split("\0")
    files = set(filter(None, tracked))
    files.update(["elementor-podcast-manager.php", "uninstall.php", "readme.txt", "LICENSE", "NOTICE"])
    # Validate the complete manifest before writing an artifact.
    for name in files:
        path = ROOT / name
        if not path.is_file() or path.is_symlink():
            raise ValueError(f"Missing or unsafe package file: {name}")
        if not path.resolve().is_relative_to(ROOT):
            raise ValueError(f"Package file outside repository: {name}")
    if output.exists() or output.with_suffix(output.suffix + ".sha256").exists():
        raise ValueError("Choose an output path that does not already exist")
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name in sorted(files):
            info = zipfile.ZipInfo(f"{SLUG}/{name}", date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, (ROOT / name).read_bytes(), compresslevel=9)
    checksum = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix + ".sha256").write_text(f"{checksum}  {output.name}\n", encoding="ascii")
    print(f"{output}: {checksum}")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("Usage: python3 tools/build-package.py /tmp/plugin-unreleased.zip")
    try:
        build(Path(sys.argv[1]).resolve())
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
