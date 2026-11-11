"""Build a public-hosting package without copying local database or logs."""
import json
import os
import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def build(destination, hosts, username, password, public_access=False, require_https=True):
    destination = Path(destination)
    if destination.exists() and any(destination.iterdir()):
        raise ValueError('Build destination must be empty')
    hosts = [host.strip().lower() for host in hosts.split(',') if host.strip()]
    if not hosts or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', h) for h in hosts):
        raise ValueError('SITE_HOSTS must contain domain names without protocol or paths')
    if not public_access and (not username or ':' in username or any(c in username for c in '\r\n')):
        raise ValueError('Invalid PANEL_USERNAME')
    if not public_access and len(password) < 16:
        raise ValueError('PANEL_PASSWORD must contain at least 16 characters')
    php = os.environ.get('PHP_BINARY', 'php')
    hashed = '' if public_access else subprocess.run([php, '-r', 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],
                            input=password, text=True, capture_output=True, check=True).stdout
    for folder in ['public', 'src']:
        shutil.copytree(ROOT / folder, destination / folder)
    for folder in ['src', 'data', 'config']:
        path = destination / folder
        path.mkdir(exist_ok=True)
        (path / '.htaccess').write_text('Require all denied\n', encoding='utf-8')
    shutil.copy2(ROOT / 'deploy' / 'HostingAccess.php', destination / 'src' / 'HostingAccess.php')
    shutil.copy2(ROOT / 'cron.php', destination / 'cron.php')
    # JSON is read as data; secrets never become executable PHP source.
    config = {'public_access': public_access, 'hosts': hosts, 'username': username, 'password_hash': hashed, 'require_https': require_https}
    (destination / 'config' / 'hosting.php').write_text(
        '<?php return json_decode(' + php_string(json.dumps(config)) + ', true, 512, JSON_THROW_ON_ERROR);\n', encoding='utf-8')
    api = (destination / 'public' / 'api.php').read_text(encoding='utf-8')
    start = api.index("$host=$_SERVER['HTTP_HOST']")
    end = api.index('try {', start)
    api = api[:start] + "require dirname(__DIR__).'/src/HostingAccess.php';\nrequireHostingAccess();\ndefine('HOSTING_BROWSER_CHECKS',true);\nsession_start(['cookie_httponly'=>true,'cookie_secure'=>" + ("true" if require_https else "false") + ",'cookie_samesite'=>'Strict','use_strict_mode'=>true]);$_SESSION['csrf']??=bin2hex(random_bytes(24));\n" + api[end:]
    (destination / 'public' / 'api.php').write_text(api, encoding='utf-8')
    (destination / 'public' / 'index.php').write_text(
        "<?php require dirname(__DIR__).'/src/HostingAccess.php'; requireHostingAccess(); header('Cache-Control: no-store'); readfile(__DIR__.'/index.html');\n", encoding='utf-8')
    (destination / 'public' / '.htaccess').write_text(
        'DirectoryIndex index.php\nOptions -Indexes\nSetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\n<Files "index.html">\nRequire all denied\n</Files>\n', encoding='utf-8')
    (destination / '.htaccess').write_text(
        'Options -Indexes\nDirectoryIndex index.php\n<FilesMatch "^(cron\\.php|\\.deploy-manifest\\.json)$">\nRequire all denied\n</FilesMatch>\n', encoding='utf-8')
    (destination / 'index.php').write_text("<?php header('Location: public/');\n", encoding='utf-8')
    return len(list(destination.rglob('*')))


def php_string(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    build(args.output, os.environ.get('SITE_HOSTS', ''), os.environ.get('PANEL_USERNAME', 'admin'), os.environ.get('PANEL_PASSWORD', ''), os.environ.get('PUBLIC_ACCESS', 'false').lower() == 'true', os.environ.get('REQUIRE_HTTPS', 'true').lower() == 'true')
    print('Hosting package built; runtime databases and logs excluded.')
