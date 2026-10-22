import gzip
import json
import tempfile
import threading
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from unittest.mock import patch
from http.server import HTTPServer

import server


class ServiceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / 'model.gz'
        self.publish({'version': 1})

    def tearDown(self):
        self.temp.cleanup()

    def publish(self, data):
        temporary = self.path.with_suffix('.tmp')
        with gzip.open(temporary, 'wt') as handle:
            json.dump(data, handle)
        temporary.replace(self.path)

    def test_load_once_and_reload_atomic_replacement(self):
        with patch.object(server.hybrid, 'load_model', wraps=server.hybrid.load_model) as load:
            store = server.ModelStore(self.path)
            original = store.get()
            self.assertIs(original, store.get())
            self.assertEqual(load.call_count, 1)
            self.publish({'version': 2})
            self.assertEqual(store.get(), {'version': 2})
            self.assertEqual(load.call_count, 2)
            self.path.write_bytes(b'bad model')
            with self.assertRaises(Exception):
                store.get()
            self.assertEqual(store.model, {'version': 2})
            self.publish({'version': 3})
            self.assertEqual(store.get(), {'version': 3})

    def test_http_signals_are_request_specific_and_required(self):
        http = HTTPServer(('127.0.0.1', 0), server.Handler)
        http.store = server.ModelStore(self.path)
        thread = threading.Thread(target=http.serve_forever, daemon=True)
        thread.start()
        url = 'http://127.0.0.1:%d/homepage' % http.server_port

        def request(data):
            req = urllib.request.Request(url, json.dumps(data).encode(), {'Content-Type': 'application/json'})
            with urllib.request.urlopen(req, timeout=3) as response:
                return json.load(response)

        def calculate(model, user, limit, mode, restricted, signals):
            return {'user': user, 'history_size': len(signals['watch_position'])}

        try:
            with patch.object(server.hybrid, 'homepage', side_effect=calculate) as homepage:
                data = {'user_id': 'first', 'limit': 11, 'mode': 'kids',
                        'include_age_restricted': False,
                        'signals': {'watch_position': [{'movie_id': 'one'}], 'wishlist': []}}
                self.assertEqual(request(data), {'user': 'first', 'history_size': 1})
                data['user_id'] = 'second'
                data['signals'] = {'watch_position': [], 'wishlist': []}
                self.assertEqual(request(data), {'user': 'second', 'history_size': 0})
                self.assertEqual(homepage.call_args.args[3:5], ('kids', False))
                del data['signals']
                with self.assertRaises(urllib.error.HTTPError) as error:
                    request(data)
                self.assertEqual(error.exception.code, 400)
                error.exception.close()
                self.assertEqual(homepage.call_count, 2)
        finally:
            http.shutdown()
            thread.join()
            http.server_close()


if __name__ == '__main__':
    unittest.main()
