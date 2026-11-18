"""Exercise the installed Psalm scanner, its cache, and worker processes."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]


class DoctrineMappingPluginTest(unittest.TestCase):
    def test_mapping_metadata_survives_cold_parallel_and_cached_scans(self):
        with tempfile.TemporaryDirectory(prefix='psalm-doctrine-mapping-') as temporary:
            workspace = Path(temporary)
            (workspace / 'fixture.php').write_text('''<?php
namespace Doctrine\\ORM\\Mapping {
    #[\\Attribute(\\Attribute::TARGET_CLASS)] final class Entity {}
    #[\\Attribute(\\Attribute::TARGET_PROPERTY | \\Attribute::TARGET_PARAMETER)] final class Column {}
    #[\\Attribute(\\Attribute::TARGET_CLASS)] final class Table {}
}
namespace Fixture {
    use Doctrine\\ORM\\Mapping as ORM;
    #[ORM\\Entity]
    class ReflectionOnlyEntity {
        public function __construct(#[ORM\\Column] private string $mappedValue) {}
    }
    #[ORM\\Entity, ORM\\Table]
    class GroupedReflectionOnlyEntity {
        public function __construct(#[ORM\\Column] private string $groupedValue) {}
    }
    class OrdinaryUnusedClass {}
}
''')
            plugin = ROOT / 'tools/psalm/plugins/DoctrineMappingPlugin.php'
            (workspace / 'psalm.xml').write_text(f'''<?xml version="1.0"?>
<psalm errorLevel="1" phpVersion="8.5" findUnusedCode="true" cacheDirectory="{workspace / 'cache'}"
       xmlns="https://getpsalm.org/schema/config">
  <projectFiles><file name="fixture.php"/></projectFiles>
  <plugins><plugin filename="{plugin}"/></plugins>
</psalm>''')
            php = os.environ.get('PSALM_TEST_PHP') or shutil.which('php')
            self.assertIsNotNone(php, 'PHP must be installed to test the real Psalm plugin.')
            psalm = ROOT / 'tools/psalm/vendor/bin/psalm'
            self.assertTrue(psalm.is_file(), 'Install locked tools/psalm dependencies before running this test.')
            for arguments in [
                ['--threads=1', '--no-cache'],
                ['--threads=4', '--no-cache'],
                ['--threads=4'],
                ['--threads=4'],
            ]:
                with self.subTest(arguments=arguments):
                    result = subprocess.run(
                        [php, str(psalm), '--config=psalm.xml', '--no-diff', '--output-format=json', *arguments],
                        cwd=workspace, capture_output=True, text=True, timeout=120,
                    )
                    self.assertEqual(2, result.returncode, result.stderr + result.stdout)
                    findings = json.loads(result.stdout)
                    mapped = [item for item in findings if 'ReflectionOnlyEntity' in item['message']]
                    self.assertEqual([], mapped, findings)
                    controls = [item for item in findings if item['type'] == 'UnusedClass' and 'OrdinaryUnusedClass' in item['message']]
                    self.assertEqual(1, len(controls), findings)


if __name__ == '__main__':
    unittest.main()
