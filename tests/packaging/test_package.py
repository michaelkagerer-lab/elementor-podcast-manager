"""QA-N4: inspect the installable artifact rather than a developer checkout."""
import hashlib
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]


class PackageTests(unittest.TestCase):
    def test_archive_is_reproducible_and_contains_only_runtime_files(self):
        with tempfile.TemporaryDirectory() as directory:
            archives = [Path(directory) / name for name in ("first.zip", "second.zip")]
            for archive in archives:
                subprocess.run([sys.executable, str(ROOT / "tools/build-package.py"), str(archive)], check=True)
                digest = hashlib.sha256(archive.read_bytes()).hexdigest()
                self.assertEqual(f"{digest}  {archive.name}\n", archive.with_suffix(".zip.sha256").read_text())
            self.assertEqual(archives[0].read_bytes(), archives[1].read_bytes())
            with zipfile.ZipFile(archives[0]) as archive:
                names = archive.namelist()
                self.assertEqual(sorted(names), names)
                for name in ("elementor-podcast-manager.php", "uninstall.php", "readme.txt", "LICENSE", "NOTICE"):
                    self.assertIn(f"elementor-podcast-manager/{name}", names)
                self.assertTrue(any("/includes/" in name for name in names))
                self.assertTrue(any("/assets/" in name for name in names))
                for name in names:
                    self.assertNotRegex(name, r"/(tests|docs|tools|node_modules|screenshots|\.github|\.git)/")
                    self.assertNotIn("seed.php", name)
                    self.assertNotIn("epm-test-", name)
                for item in archive.infolist():
                    self.assertEqual((1980, 1, 1, 0, 0, 0), item.date_time)
                self.assertIn(b"GNU GENERAL PUBLIC LICENSE", archive.read("elementor-podcast-manager/LICENSE"))
                self.assertIn(b"Copyright (c) 2026 VoltAgent", archive.read("elementor-podcast-manager/NOTICE"))


if __name__ == "__main__":
    unittest.main()
