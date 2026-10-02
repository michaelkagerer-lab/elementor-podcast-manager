"""Third-party shutdown messages must not become IDs in destructive guard probes."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SeedOutput(unittest.TestCase):
    def test_porcelain_ids_survive_shutdown_noise(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / '.epm-test-site').touch()
            state = root / 'posts.json'
            state.write_text('[]')
            wp = root / 'wp'
            wp.write_text('''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
state = Path(os.environ['EPM_PROBE_STATE'])
ids = json.loads(state.read_text())
args = sys.argv[1:]
if args[:2] == ['post', 'create']:
    identifier = str(5 + len(ids))
    ids.append(identifier)
    state.write_text(json.dumps(ids))
    print(identifier)
    print('PHP: 2026-10-02 [notice] Elementor shutdown diagnostic')
elif args[:2] == ['post', 'get']:
    sys.exit(0 if args[2] in ids else 1)
elif args[0] == 'eval-file':
    if os.environ.get('EPM_ALLOW_TEST_SEED') != '1':
        sys.exit(1)
    state.write_text('[]')
else:
    sys.exit(2)
''')
            wp.chmod(0o755)
            result = subprocess.run([str(ROOT / 'tests/safety/seed.sh')],
                                    env={**os.environ, 'WP_DIR': str(root), 'WP_CLI': str(wp),
                                         'EPM_PROBE_STATE': str(state)},
                                    text=True, capture_output=True, timeout=10)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertIn('unauthorized reset refused', result.stdout)
            self.assertEqual([], json.loads(state.read_text()))


if __name__ == '__main__':
    unittest.main()
