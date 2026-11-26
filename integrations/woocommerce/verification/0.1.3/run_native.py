"""Run the native PHP server and HTTP tests in one process environment."""
import argparse
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument("--php", required=True, help="PHP CLI binary or wrapper with fileinfo/XMLReader enabled")
parser.add_argument("--wordpress", required=True, help="Unmodified official WordPress source root")
parser.add_argument("--plugin", required=True, help="ShelfRelay plugin directory")
parser.add_argument("--port", type=int, default=18739)
args = parser.parse_args()
root = Path(__file__).resolve().parent
env = dict(os.environ, SHELFRELAY_WP_SOURCE=str(Path(args.wordpress).resolve()), SHELFRELAY_PLUGIN_SOURCE=str(Path(args.plugin).resolve()))
url = f"http://127.0.0.1:{args.port}"
with (root / "php-server.log").open("w") as log:
    server = subprocess.Popen([args.php, "-d", "upload_max_filesize=30M", "-d", "post_max_size=32M", "-d", "display_errors=1", "-S", f"127.0.0.1:{args.port}", "-t", str(root), str(root / "upload-router.php")], env=env, stdout=log, stderr=log)
    try:
        for _ in range(50):
            try:
                urllib.request.urlopen(url, timeout=1).close()
                break
            except OSError:
                if server.poll() is not None:
                    raise RuntimeError("PHP server stopped; inspect php-server.log")
                time.sleep(0.1)
        else:
            raise RuntimeError("PHP server did not become ready; inspect php-server.log")
        result = subprocess.run([sys.executable, str(root / "test_upload.py"), url], env=env, check=False)
    finally:
        server.terminate()
        server.wait(timeout=5)
sys.exit(result.returncode)
