"""Run Laravel and the separate Python API against a temporary, socket-only MariaDB.

No production config is loaded. Database and datadir are created from scratch.
"""
import argparse
import base64
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
from uuid import uuid4

import pymysql

parser = argparse.ArgumentParser()
parser.add_argument('--api-root', required=True, type=Path)
args = parser.parse_args()
app = Path(__file__).resolve().parents[1]
api = args.api_root.resolve()
if not (api / 'opt/blancco-api/application_api.py').is_file():
    raise SystemExit('Missing new application API in --api-root')
if (app/'contracts/installation-policy-v1.json').read_bytes() != (api/'contracts/installation-policy-v1.json').read_bytes():
    raise SystemExit('Cross-runtime policy fixtures differ; update both repositories together')
if (app/'contracts/openapi.json').read_bytes() != (api/'contracts/openapi.json').read_bytes():
    raise SystemExit('API contracts differ across repositories')
for binary in ('mariadb-install-db', 'mariadbd', 'php'):
    if not shutil.which(binary):
        raise SystemExit('Missing local executable: ' + binary)

with tempfile.TemporaryDirectory(prefix='blancco-integration-', dir='/tmp') as tmp:
    root = Path(tmp)
    socket = str(root / 'mysql.sock')
    database = 'blancco_test_' + uuid4().hex[:16]
    with (root/'server.log').open('w') as output:
        subprocess.run(['mariadb-install-db', '--no-defaults', '--datadir='+str(root/'data'),
                        '--auth-root-authentication-method=normal', '--skip-test-db'],
                       stdout=output, stderr=subprocess.STDOUT, check=True)
        server = subprocess.Popen(['mariadbd', '--no-defaults', '--datadir='+str(root/'data'),
                                   '--socket='+socket, '--pid-file='+str(root/'pid'), '--skip-networking'],
                                  stdout=output, stderr=subprocess.STDOUT)
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError('Temporary MariaDB startup failed')
                try:
                    conn = pymysql.connect(unix_socket=socket,user='root')
                    break
                except pymysql.MySQLError:
                    time.sleep(.1)
            else:
                raise RuntimeError('Temporary MariaDB startup timed out')
            with conn.cursor() as cursor:
                cursor.execute('CREATE DATABASE `'+database+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
            conn.close()
            env = dict(os.environ,APP_ENV='testing',APP_KEY='base64:'+base64.b64encode(os.urandom(32)).decode(),
                       DB_CONNECTION='mysql',DB_DATABASE=database,DB_USERNAME='root',DB_PASSWORD='',DB_SOCKET=socket,
                       DB_URL='',DB_HOST='127.0.0.1',DB_PORT='3306',CACHE_STORE='array',SESSION_DRIVER='array',
                       BLANCCO_SYNC_ENABLED='false',BLANCCO_APPLICATION_TEST_SOCKET=socket,
                       BLANCCO_APPLICATION_TEST_DATABASE=database)
            subprocess.run(['php','artisan','migrate','--force','--no-interaction'],cwd=app,env=env,check=True)
            subprocess.run(['php','artisan','test','--compact'],cwd=app,env=env,check=True)
            subprocess.run([sys.executable,'-m','unittest','discover','-s','tests','-p','test_application*.py','-v'],cwd=api,env=env,check=True)
        finally:
            server.terminate()
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
print('OK: Laravel migrations, PHP tests and new API passed against isolated MariaDB.')
