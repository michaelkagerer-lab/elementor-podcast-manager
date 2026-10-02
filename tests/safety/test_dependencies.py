"""Downloaded code must match its pin before replacing any cached artifact."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('dependencies', ROOT / 'tests/bin/dependencies.py')
dependencies = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dependencies)

class VerifiedDownloads(unittest.TestCase):
    def test_corrupt_download_keeps_existing_file_and_is_never_accepted(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = root / 'versions.json'
            manifest.write_text(json.dumps({'artifacts': {'probe': {'1.0': {
                'url': 'https://example.test/probe.zip',
                'sha256': hashlib.sha256(b'verified archive').hexdigest(),
            }}}}))
            archive = root / 'archive.zip'
            archive.write_bytes(b'previous cached file')
            def delivered_corrupt_response(args, **kwargs):
                Path(args[args.index('-o') + 1]).write_bytes(b'truncated or changed download')
            with patch.object(dependencies, 'MANIFEST', manifest), patch.object(dependencies.subprocess, 'run', delivered_corrupt_response):
                with self.assertRaisesRegex(ValueError, 'SHA-256 mismatch'):
                    dependencies.download('probe', '1.0', archive)
            self.assertEqual(archive.read_bytes(), b'previous cached file')
            self.assertEqual(list(root.glob('.verified-download-*')), [])

    def test_verified_response_replaces_cache_and_reuse_needs_no_network(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            manifest = root / 'versions.json'
            manifest.write_text(json.dumps({'artifacts': {'probe': {'1.0': {
                'url': 'https://example.test/probe.zip',
                'sha256': hashlib.sha256(b'verified archive').hexdigest(),
            }}}}))
            archive = root / 'archive.zip'
            def delivered_response(args, **kwargs):
                Path(args[args.index('-o') + 1]).write_bytes(b'verified archive')
            with patch.object(dependencies, 'MANIFEST', manifest), patch.object(dependencies.subprocess, 'run', delivered_response):
                dependencies.download('probe', '1.0', archive)
            with patch.object(dependencies, 'MANIFEST', manifest), patch.object(dependencies.subprocess, 'run', side_effect=AssertionError('cached verified artifacts need no network')):
                dependencies.download('probe', '1.0', archive)
            self.assertEqual(archive.read_bytes(), b'verified archive')

class RuntimeInventory(unittest.TestCase):
    def test_shutdown_notices_do_not_corrupt_locale_or_version_json(self):
        # Elementor on PHP 8.4 can write a shutdown diagnostic after eval's
        # JSON. The runner must retain accurate inventory values nevertheless.
        noise = 'PHP: 2026-10-02 [notice] third-party shutdown diagnostic'
        def wp_output(args, **kwargs):
            if args[1:3] == ['core', 'version']:
                return '7.1.2\n'
            if args[1:3] == ['cli', 'version']:
                return 'WP-CLI 2.12.0\n'
            if args[1] == 'eval':
                value = ['de_DE'] if 'get_available_languages' in args[-1] else '8.4.15'
                if 'EPM_INVENTORY_JSON:' in args[-1]:
                    return noise + '\nEPM_INVENTORY_JSON:' + json.dumps(value) + '\n' + noise
                return (json.dumps(value) if isinstance(value, list) else value) + noise
            return '[]\n'
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'inventory.json'
            with patch.object(dependencies.subprocess, 'check_output', side_effect=wp_output):
                dependencies.inventory('test-wp', output)
            data = json.loads(output.read_text())
            self.assertEqual('8.4.15', data['php'])
            self.assertEqual(['de_DE'], data['core_languages'])
            self.assertEqual([], data['plugins'])

if __name__ == '__main__':
    unittest.main()
