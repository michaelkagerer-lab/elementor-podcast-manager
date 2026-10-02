#!/usr/bin/env python3
"""Pinned test dependencies, verified downloads and runtime inventory."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

MANIFEST = Path(__file__).resolve().parents[1] / 'versions.json'


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def download(kind, version, output):
    spec = json.loads(MANIFEST.read_text())['artifacts'][kind][version]
    output = Path(output)
    if output.is_file() and digest(output) == spec['sha256']:
        return
    output.parent.mkdir(parents=True, exist_ok=True)
    handle, temporary = tempfile.mkstemp(prefix='.verified-download-', dir=output.parent)
    os.close(handle)
    try:
        subprocess.run(['curl', '--connect-timeout', '10', '--max-time', '60',
                        '--retry', '2', '--retry-max-time', '120', '-fsSL',
                        '-o', temporary, spec['url']], check=True, timeout=150)
        if digest(temporary) != spec['sha256']:
            raise ValueError(f'{kind} {version}: SHA-256 mismatch; refusing the downloaded artifact')
        Path(temporary).replace(output)
    finally:
        Path(temporary).unlink(missing_ok=True)


def inventory(wp, output):
    def command(*args):
        return subprocess.check_output([wp, *args], text=True, timeout=60).strip()
    def json_eval(expression):
        marker = 'EPM_INVENTORY_JSON:'
        output = command('eval', 'echo "\\n' + marker + '"; echo wp_json_encode(' + expression + '); echo "\\n";')
        for line in output.splitlines():
            if line.startswith(marker):
                return json.loads(line[len(marker):])
        raise ValueError('WP-CLI did not return the inventory JSON marker')

    data = {
        'profile': os.environ.get('EPM_TEST_PROFILE', 'current'),
        'wordpress': command('core', 'version'),
        'wp_cli': command('cli', 'version'),
        'php': json_eval('PHP_VERSION'),
        'plugins': json.loads(command('plugin', 'list', '--fields=name,status,version', '--format=json')),
        'themes': json.loads(command('theme', 'list', '--fields=name,status,version', '--format=json')),
        'core_languages': json_eval('get_available_languages()'),
        'manifest': json.loads(MANIFEST.read_text()),
    }
    Path(output).write_text(json.dumps(data, indent=2) + '\n')


if __name__ == '__main__':
    try:
        if sys.argv[1] == 'value':
            print(json.loads(MANIFEST.read_text())['profiles'][sys.argv[2]][sys.argv[3]])
        elif sys.argv[1] == 'download':
            download(*sys.argv[2:])
        elif sys.argv[1] == 'inventory':
            inventory(*sys.argv[2:])
        else:
            raise ValueError('Expected value, download or inventory')
    except (KeyError, ValueError, subprocess.SubprocessError) as error:
        sys.exit(f'Pinned test dependency error: {error}')
