"""Upload an explicit hosting package using verified FTPS; never delete remote data."""
import ftplib
import os
import posixpath
import ssl
import uuid
from pathlib import Path


def inventory(source):
    source = Path(source).resolve()
    files = []
    for path in source.rglob('*'):
        if path.is_symlink():
            raise ValueError('Symlinks are not deployable')
        if path.is_file():
            relative = path.relative_to(source).as_posix()
            if relative.startswith('data/') and relative != 'data/.htaccess':
                raise ValueError('Runtime data is not deployable')
            if path.suffix in ('.sqlite', '.log', '.lock') or path.name.endswith(('-wal', '-shm')):
                raise ValueError('Runtime file in deployment package')
            files.append((relative, path))
    required = ['.htaccess', 'src/.htaccess', 'data/.htaccess', 'config/.htaccess']
    names = {name for name, _ in files}
    if any(name not in names for name in required):
        raise ValueError('Access protection files are required')
    return sorted(files, key=lambda pair: (not pair[0].endswith('.htaccess'), pair[0]))


def upload(source):
    files = inventory(source)
    remote = os.environ.get('FTP_REMOTE_DIR', '/htdocs').rstrip('/')
    if not remote.startswith('/') or '..' in remote.split('/') or not remote:
        raise ValueError('FTP_REMOTE_DIR must be an absolute non-root directory')
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=30)
    try:
        ftp.connect(os.environ['FTP_HOST'], int(os.environ.get('FTP_PORT', '21')))
        ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
        ftp.prot_p()
        ftp.cwd(remote)  # Never guess/create an account's document root.
        for relative, path in files:
            parent = posixpath.dirname(relative)
            ftp.cwd(remote)
            for part in parent.split('/') if parent else []:
                try:
                    ftp.cwd(part)
                except ftplib.error_perm:
                    ftp.mkd(part)
                    ftp.cwd(part)
            filename = posixpath.basename(relative)
            temporary = '.upload-' + uuid.uuid4().hex
            with path.open('rb') as content:
                ftp.storbinary('STOR ' + temporary, content)
            ftp.rename(temporary, filename)
        print('Uploaded', len(files), 'files; existing database and logs preserved.')
    finally:
        ftp.close()


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    args = parser.parse_args()
    upload(args.source)
