"""Unmarked WordPress directories must be rejected before any mutation."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class SiteGuards(unittest.TestCase):
    def test_every_provisioning_runner_refuses_an_unmarked_site(self):
        for runner in ['tests/bin/setup-wp.sh', 'tests/run-all.sh', 'tests/multisite/run.sh', 'tests/compat/run.sh', 'tests/safety/seed.sh']:
            with self.subTest(runner=runner), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'site').mkdir()
                config = root / 'site/wp-config.php'
                config.write_text('<?php /* existing site: must remain unchanged */')
                original = config.read_bytes()
                cli = root / 'wp'
                cli.write_text('#!/bin/sh\ntouch "$WP_DIR/cli-was-called"\nexit 99\n')
                cli.chmod(0o755)
                result = subprocess.run([str(ROOT / runner)], cwd=ROOT, env={**os.environ, 'WP_DIR': directory, 'WP_CLI': str(cli)}, capture_output=True, text=True, timeout=10)
                self.assertEqual(result.returncode, 2, result.stdout + result.stderr)
                self.assertIn('Refusing', result.stderr)
                self.assertEqual(config.read_bytes(), original)
                self.assertFalse((root / '.epm-test-site').exists())
                self.assertFalse((root / 'cli-was-called').exists())

class SuiteModes(unittest.TestCase):
    def test_browser_only_and_skip_browser_cannot_disable_every_suite(self):
        with tempfile.TemporaryDirectory() as directory:
            (Path(directory) / 'site').mkdir()
            result = subprocess.run([str(ROOT / 'tests/run-all.sh')], cwd=ROOT,
                                    env={**os.environ, 'WP_DIR': directory,
                                         'EPM_E2E_ONLY': '1', 'SKIP_E2E': '1'},
                                    capture_output=True, text=True, timeout=10)
            self.assertEqual(2, result.returncode, result.stdout + result.stderr)
            self.assertIn('mutually exclusive', result.stderr)
            self.assertFalse((Path(directory) / '.epm-test-site').exists())

if __name__ == '__main__':
    unittest.main()
