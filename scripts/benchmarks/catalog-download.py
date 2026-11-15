#!/usr/bin/env python3
"""Compare whole-body and streaming SHA-256 over a local 16 MiB HTTP fixture."""

import argparse
import hashlib
import http.server
import json
from pathlib import Path
import subprocess
import threading

PHP = r'''
require $argv[1] . '/core/vendor/autoload.php';
$mode = $argv[2];
$client = Symfony\Component\HttpClient\HttpClient::create();
memory_reset_peak_usage();
$baseline = memory_get_usage();
$start = hrtime(true);
$response = $client->request('GET', $argv[3], $mode === 'after' ? ['buffer' => false] : []);
if ($mode === 'before') {
    $sha = hash('sha256', $response->getContent());
} else {
    $response->getHeaders();
    $hash = hash_init('sha256');
    foreach ($client->stream($response) as $chunk) {
        hash_update($hash, $chunk->getContent());
    }
    $sha = hash_final($hash);
}
echo json_encode([
    'mode' => $mode,
    'php' => PHP_VERSION,
    'bytes' => 16777216,
    'additional_peak_bytes' => memory_get_peak_usage() - $baseline,
    'milliseconds' => (hrtime(true) - $start) / 1e6,
    'sha256' => $sha,
], JSON_THROW_ON_ERROR), "\n";
'''


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'application/java-archive')
        self.send_header('Content-Length', '16777216')
        self.end_headers()
        for _ in range(256):
            self.wfile.write(b'x' * 65536)

    def log_message(self, *_args):
        pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='PHP executable with the Core dependencies available')
    args = parser.parse_args()
    repository = Path(__file__).resolve().parents[2]
    server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    expected = hashlib.sha256()
    for _ in range(256):
        expected.update(b'x' * 65536)
    try:
        for mode in ('before', 'after'):
            output = subprocess.check_output([
                args.php, '-r', PHP, str(repository), mode,
                f'http://127.0.0.1:{server.server_port}/BuildTools.jar',
            ], text=True)
            result = json.loads(output)
            if result['sha256'] != expected.hexdigest():
                raise RuntimeError('Downloaded fixture digest mismatch')
            print(json.dumps(result), flush=True)
    finally:
        server.shutdown()
        server.server_close()


if __name__ == '__main__':
    main()
