#!/usr/bin/env python3
"""Validate complete German catalogs, printf arguments and WordPress JS hashes."""
import ast
from collections import Counter
import gettext
import hashlib
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
LANG = ROOT / 'languages'
DOMAIN = 'elementor-podcast-manager'


def entries(path):
    result, entry, field = [], {}, None
    for line in path.read_text().splitlines() + ['']:
        if not line.strip():
            if entry.get('msgid'):
                result.append(entry)
            entry, field = {}, None
        elif line.startswith('#'):
            continue
        elif line.startswith('"'):
            entry[field] += ast.literal_eval(line)
        else:
            field, value = line.split(' ', 1)
            entry[field] = ast.literal_eval(value)
    return result


def arguments(value):
    # Position and conversion type both matter; %% is a literal percent.
    result, position = [], 1
    for match in re.finditer(r'%(?:%|(?:(\d+)\$)?[-+ 0#]*(?:\d+)?(?:\.\d+)?([bcdeEfFgGosuxX]))', value):
        if match.group(0) == '%%':
            continue
        result.append((int(match.group(1) or position), match.group(2)))
        if not match.group(1):
            position += 1
    return Counter(result)


class GermanCatalog(unittest.TestCase):
    def test_complete_compiled_catalog_and_placeholders(self):
        po = {(e.get('msgctxt'), e['msgid']): e for e in entries(LANG / f'{DOMAIN}-de_DE.po')}
        with (LANG / f'{DOMAIN}-de_DE.mo').open('rb') as source:
            compiled = gettext.GNUTranslations(source)
        for original in entries(LANG / f'{DOMAIN}.pot'):
            key = (original.get('msgctxt'), original['msgid'])
            with self.subTest(text=key):
                self.assertIn(key, po)
                translated = po[key]
                values = ([translated.get('msgstr[0]'), translated.get('msgstr[1]')]
                          if original.get('msgid_plural') else [translated.get('msgstr')])
                for value in values:
                    self.assertTrue(value, 'missing translation')
                    self.assertEqual(arguments(original['msgid']), arguments(value))
                lookup = ('\x04'.join(key) if key[0] else key[1])
                if original.get('msgid_plural'):
                    self.assertEqual(values[0], compiled.ngettext(lookup, original['msgid_plural'], 1))
                    self.assertEqual(values[1], compiled.ngettext(lookup, original['msgid_plural'], 2))
                else:
                    self.assertEqual(values[0], compiled.gettext(lookup))
        self.assertEqual(len(po), len(entries(LANG / f'{DOMAIN}.pot')))

    def test_javascript_catalogs_use_source_path_hashes(self):
        for script in (ROOT / 'admin/js').glob('*.js'):
            if not re.search(r'\b(?:__|_n|_x)\s*\(', script.read_text()):
                continue
            relative = script.relative_to(ROOT).as_posix()
            digest = hashlib.md5(relative.encode()).hexdigest()
            data = json.loads((LANG / f'{DOMAIN}-de_DE-{digest}.json').read_text())
            self.assertEqual(relative, data['source'])
            catalog = data['locale_data']['messages']
            self.assertEqual('de_DE', catalog['']['lang'])
            self.assertEqual('nplurals=2; plural=(n != 1);', catalog['']['plural-forms'])
            self.assertGreater(len(catalog), 1)
            self.assertTrue(all(all(value) for key, value in catalog.items() if key))


if __name__ == '__main__':
    unittest.main()
