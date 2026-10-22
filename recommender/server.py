#!/usr/bin/env python3
"""Single-worker, loopback-only inference service; no third-party dependencies."""
import argparse
import json
import logging
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

import hybrid


class ModelStore:
    def __init__(self, path):
        self.path = Path(path)
        self.version = None
        self.model = None
        self.get()

    def get(self):
        stat = self.path.stat()
        version = (stat.st_ino, stat.st_mtime_ns, stat.st_size)
        if version != self.version:
            # Training publishes with os.replace. Retain the previous model until
            # the new artifact has loaded successfully; never mutate shared data.
            model = hybrid.load_model(self.path)
            self.model, self.version = model, version
            logging.info('Recommendation model loaded')
        return self.model


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def log_message(self, *args):
        pass  # Do not log user signals or request paths.

    def reply(self, status, payload):
        body = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self.reply(200 if self.path == '/health' else 404,
                   {'ready': self.server.store.model is not None} if self.path == '/health' else {})

    def do_POST(self):
        if self.path != '/homepage':
            self.reply(404, {})
            return
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 < length <= 2_000_000:
                self.reply(413, {'error': 'Invalid body size'})
                return
            data = json.loads(self.rfile.read(length))
            if not isinstance(data, dict):
                raise ValueError()
            user = data['user_id']
            limit = data['limit']
            mode = data['mode']
            restricted = data['include_age_restricted']
            signals = data['signals']
            if (not isinstance(user, str) or not user or len(user) > 255
                    or type(limit) is not int or not 1 <= limit <= 1251
                    or mode not in ('adult', 'kids') or type(restricted) is not bool
                    or not isinstance(signals, dict)):
                raise ValueError()
            for key, maximum in [('watch_position', 1000), ('wishlist', 1251)]:
                rows = signals.get(key)
                if not isinstance(rows, list) or len(rows) > maximum or any(not isinstance(row, dict) for row in rows):
                    raise ValueError()
        except (ValueError, KeyError, TypeError):
            self.reply(400, {'error': 'Invalid request'})
            return
        try:
            model = self.server.store.get()
            payload = hybrid.homepage(model, user, limit, mode, restricted, signals)
            self.reply(200, payload)
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception:
            logging.error('Recommendation calculation or model reload failed')
            self.reply(503, {'error': 'Recommendation unavailable'})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--model', type=Path, required=True)
    parser.add_argument('--port', type=int, default=8765)
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    store = ModelStore(args.model)
    # Sequential inference bounds memory and CPU on the two-core VPS.
    server = HTTPServer(('127.0.0.1', args.port), Handler)
    server.store = store
    logging.info('Recommendation service ready on loopback port %d', args.port)
    server.serve_forever()


if __name__ == '__main__':
    main()
