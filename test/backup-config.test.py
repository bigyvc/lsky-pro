import json
import os
from pathlib import Path
import sqlite3
import shutil
import subprocess
import tempfile
import unittest

SCRIPT = (Path(__file__).resolve().parents[1] / 'tools/backup-github.sh').read_text()
DB_CHECK = SCRIPT.split("python3 - <<'PYDB'\n", 1)[1].split('\nPYDB\n', 1)[0]
SNAPSHOT = SCRIPT.split('python3 - "$STAGE" <<\'PY\'\n', 1)[1].split('\nPY\n', 1)[0]


class BackupTest(unittest.TestCase):
    def verify_config(self, config):
        return subprocess.run(['python3', '-c', DB_CHECK], env={**os.environ, 'DB_CONFIG': json.dumps(config)}, capture_output=True, text=True)

    def test_default_sqlite(self):
        self.assertEqual(self.verify_config({'driver': 'sqlite', 'path': '/var/www/html/database/database.sqlite'}).returncode, 0)

    def test_other_database_does_not_back_up_stale_sqlite(self):
        for cfg in [{'driver': 'mysql', 'path': '/var/www/html/database/database.sqlite'}, {'driver': 'sqlite', 'path': '/different.sqlite'}]:
            self.assertNotEqual(self.verify_config(cfg).returncode, 0)

    def test_database_probe_bootstraps_autoloader_before_application(self):
        php = os.environ.get('LSKY_TEST_PHP') or shutil.which('php')
        if not php:
            self.skipTest('PHP runtime unavailable')
        probe = SCRIPT.split("php -r '\n", 1)[1].split("\n')", 1)[0]
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'vendor').mkdir()
            (root / 'bootstrap').mkdir()
            (root / 'vendor/autoload.php').write_text("""<?php
class ProbeApp { function make($name) { return new ProbeService(); } }
class ProbeService {
 function bootstrap() {}
 function connection() { return $this; }
 function getDriverName() { return 'sqlite'; }
 function getConfig($name) { return '/var/www/html/database/database.sqlite'; }
}
""")
            (root / 'bootstrap/app.php').write_text('<?php return new ProbeApp();')
            code = probe.replace('/var/www/html', str(root))
            result = subprocess.run([php, '-r', code], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout)['driver'], 'sqlite')
            # The original missing-autoloader bug must return failure, not empty success.
            (root / 'vendor/autoload.php').unlink()
            failed = subprocess.run([php, '-r', code], capture_output=True, text=True)
            self.assertNotEqual(failed.returncode, 0)
            self.assertNotIn('TEST_SECRET', failed.stderr)

    def test_snapshot_has_mapping_and_configuration(self):
        with tempfile.TemporaryDirectory() as temp:
            prefix = Path(temp) / 'input'
            required = ['/opt/lsky/data/.env', '/opt/lsky/stack.env', '/opt/lsky/source/deploy/compose.stack.yaml', '/etc/caddy/Caddyfile']
            for path in required:
                target = prefix / path.lstrip('/')
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('fixture')
            db = prefix / 'opt/lsky/data/database/database.sqlite'
            db.parent.mkdir(parents=True, exist_ok=True)
            with sqlite3.connect(db) as connection:
                connection.execute('CREATE TABLE telegram_files (path TEXT)')
                connection.execute("INSERT INTO telegram_files VALUES ('0/image.png')")
            stage = Path(temp) / 'stage'
            stage.mkdir()
            code = SNAPSHOT
            for root in ['/opt/', '/etc/', '/usr/']:
                code = code.replace("'" + root, "'" + str(prefix) + root)
            subprocess.run(['python3', '-c', code, str(stage)], check=True)
            snapshots = list(stage.rglob('database.sqlite'))
            self.assertEqual(len(snapshots), 1)
            with sqlite3.connect(snapshots[0]) as connection:
                self.assertEqual(connection.execute('SELECT path FROM telegram_files').fetchone()[0], '0/image.png')
            self.assertTrue((stage / 'backup-info.json').is_file())
            self.assertFalse(list(stage.rglob('github-token')))


if __name__ == '__main__':
    unittest.main()
