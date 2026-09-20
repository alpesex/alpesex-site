"""Exercise the real PHP API on a disposable MariaDB database, never production."""
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
assert os.environ.get('DB_NAME') == 'cpmp_test_mobile', 'Disposable database required'

def parallel(functions):
    with concurrent.futures.ThreadPoolExecutor(max_workers=len(functions)) as pool:
        return list(pool.map(lambda f: f(), functions))

with tempfile.TemporaryDirectory(prefix='cpmp-api-test-') as directory:
    temp = Path(directory)
    sessions = temp / 'sessions'
    sessions.mkdir()
    env = dict(os.environ, ALPESEX_APP_DIR=str(ROOT / 'server/tests/fixture'),
               ALPESEX_APPLICATION_DIR=str(temp / 'storage'), PHP_CLI_SERVER_WORKERS='4')
    php = ['php', '-d', f'session.save_path={sessions}']
    subprocess.run(php + [str(ROOT / 'server/tests/fixture/seed.php')], env=env, check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (temp / 'server.log').open('w+') as log:
        server = subprocess.Popen(php + ['-S', f'127.0.0.1:{port}', '-t', str(ROOT / 'server/public')],
                                  env=env, stdout=log, stderr=log, start_new_session=True)
        def call(action, payload=None, user=1, copy=1, raw=None, content_type=None):
            headers = {'Cookie': f'ALPESEXSESSID=test{user}copy{copy}'}
            body = raw if raw is not None else json.dumps(payload).encode() if payload is not None else None
            if body is not None:
                headers['Content-Type'] = content_type or 'application/json'
            request = urllib.request.Request(f'http://127.0.0.1:{port}/api/application/?action={action}', data=body, headers=headers)
            try:
                response = urllib.request.urlopen(request, timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            data = response.read()
            return response.status, json.loads(data) if 'application/json' in response.headers.get('Content-Type', '') else data
        def upload(project, contents, copy=1):
            boundary = 'cpmp-test-boundary'
            body = b''
            for key, value in [('projectId', project), ('ref', 'DOC-1'), ('family', 'TEST')]:
                body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test.txt"\r\nContent-Type: text/plain\r\n\r\n'.encode() + contents + f'\r\n--{boundary}--\r\n'.encode()
            return call('document-upload', raw=body, content_type=f'multipart/form-data; boundary={boundary}', copy=copy)
        try:
            for _ in range(100):
                try:
                    status, result = call('session')
                    break
                except OSError:
                    time.sleep(.05)
            assert status == 200, (status, result)
            project = {'meta': {'portfolioId': 'shared-test', 'nomProjet': 'Shared project'}, 'todo': []}
            status, saved = call('project-save', {'project': project, 'revision': 0})
            assert status == 200 and saved['revision'] == 1, (status, saved)
            project_id = saved['id']
            project['meta']['cloudId'] = project_id
            attempts = parallel([lambda: call('project-save', {'project': project, 'revision': 1}, copy=1),
                                 lambda: call('project-save', {'project': project, 'revision': 1}, copy=2)])
            assert sorted(x[0] for x in attempts) == [200, 409], attempts
            assert call('project-save', {'project': project, 'revision': 2}, user=3)[0] == 403
            renamed = {'meta': {'portfolioId': 'changed-local-id', 'cloudId': project_id, 'nomProjet': 'Shared project'}, 'todo': []}
            status, renamed_saved = call('project-save', {'project': renamed, 'revision': 2})
            assert status == 200 and renamed_saved['id'] == project_id and renamed_saved['revision'] == 3, (status, renamed_saved)
            status, current_projects = call('projects')
            assert status == 200 and len(current_projects['projects']) == 1 and current_projects['projects'][0]['localId'] == 'changed-local-id'
            status, manager = call('projects', user=2)
            assert status == 200 and manager['projects'][0]['access'] == 'editor'
            assert call('projects', user=4) == (200, {'projects': []})
            assert call('documents-status', {'projectId': project_id, 'documents': []}, user=4)[0] == 404
            uploads = parallel([lambda: upload(project_id, b'version A', 1), lambda: upload(project_id, b'version B', 2)])
            assert all(x[0] == 200 for x in uploads), uploads
            assert call('document-open&projectId=' + project_id + '&ref=DOC-1')[1] in [b'version A', b'version B']
            files = list((temp / 'storage/documents/1').iterdir())
            assert len(files) == 1, files
            assert b'version' not in files[0].read_bytes(), 'Document must be encrypted at rest'
            assert upload(project_id, b'x' * (1024 * 1024 + 1))[0] == 413
            assert call('project-delete', {'projectId': project_id, 'revision': 1})[0] == 409
            assert call('project-delete', {'projectId': project_id, 'revision': 3}, user=3)[0] == 403
            assert call('project-delete', {'projectId': project_id, 'revision': 3}) == (200, {'ok': True, 'cleanupPending': 0})
            assert call('projects') == (200, {'projects': []})
            assert call('project-save', {'project': project, 'revision': 3})[0] == 410
            assert call('document-open&projectId=' + project_id + '&ref=DOC-1')[0] == 404
            assert not list((temp / 'storage/documents/1').iterdir())
            # The tombstone remains authoritative and cannot be recreated under an alias.
            assert call('project-save', {'project': renamed, 'revision': 3})[0] == 410
            # Another recently active device blocks simultaneous use and reveals its display name.
            busy_device = hashlib.sha256(b'busy-device').hexdigest()
            busy = call('activate', {'licenseToken': 'test-license-1', 'deviceIdentifier': busy_device,
                'deviceName': 'TABLETTE-ATELIER', 'platform': 'android'}, copy=4)
            assert busy[0] == 409 and busy[1] == {'error': 'DEVICE_ALREADY_CONNECTED', 'deviceName': 'Test'}, busy
            assert call('device-disconnect', {}, copy=1) == (200, {'ok': True})
            activations = parallel([lambda i=i: call('activate', {'licenseToken': 'test-license-1',
                'deviceIdentifier': hashlib.sha256(f'new-device-{i}'.encode()).hexdigest(),
                'deviceName': 'Test concurrent', 'platform': 'android'}, copy=i) for i in range(1, 5)])
            assert sorted(x[0] for x in activations) == [200, 409, 409, 409], activations
            assert all(x[1].get('deviceName') == 'Test concurrent' for x in activations if x[0] == 409), activations
            print('PASS: concurrent edits, licence roles, organization isolation, encrypted document replacement, size limit, deletion tombstones, single active device')
        except BaseException:
            log.flush()
            log.seek(0)
            print(log.read())
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)
