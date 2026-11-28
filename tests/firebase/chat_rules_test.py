"""Exercise the real RTDB rules against a local, disposable database emulator.

Start the emulator on 127.0.0.1:19090, then run python3 tests/firebase/chat_rules_test.py.
This script only contacts localhost and refuses a non-demo namespace.
"""
import base64
import json
import time
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

BASE = 'http://127.0.0.1:19090'
NS = 'demo-zostream-chat'


def request(method, path, value=None, token='owner'):
    args = {'ns': NS}
    headers = {'Content-Type': 'application/json'}
    if token == 'owner':
        headers['Authorization'] = 'Bearer owner'
    elif token:
        args['auth'] = token
    data = json.dumps(value).encode() if value is not None else None
    req = Request(BASE + '/' + path + '.json?' + urlencode(args), data=data, headers=headers, method=method)
    try:
        with urlopen(req, timeout=10) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        return error.code, error.read().decode()


def jwt(uid, session='s', name=None):
    def encode(value):
        return base64.urlsafe_b64encode(json.dumps(value).encode()).rstrip(b'=').decode()
    now = int(time.time())
    return encode({'alg': 'none', 'typ': 'JWT'}) + '.' + encode({
        'sub': uid, 'user_id': uid, 'aud': NS, 'iss': 'https://securetoken.google.com/' + NS,
        'iat': now, 'exp': now + 3600, 'auth_time': now,
        'firebase': {'sign_in_provider': 'custom'}, 'chat_session': session,
        'chat_name': name or uid.title(),
    }) + '.'


def check(method, path, expected, value=None, token=None):
    status, response = request(method, path, value, token)
    assert status == expected, (method, path, expected, status, response)


rules = json.loads((Path(__file__).resolve().parents[2] / 'Library/firebase/database.rules.json').read_text())
status, response = request('PUT', '.settings/rules', rules)
assert status == 200, response
now = int(time.time() * 1000)
seed = {'chat_v1': {
    'access': {'alice': {'s': {'expires_at': now + 90000}}, 'bob': {'s': {'expires_at': now + 90000}}, 'eve': {'s': {'expires_at': now + 90000}}},
    'rooms': {'global': {'state': {'active': True, 'can_send': True}, 'members': {'alice': {'active': True, 'can_send': True}, 'bob': {'active': True, 'can_send': True}}}},
    'user_messages': {'alice': {'global': {'1': {'id': '1', 'cursor': 1}}}, 'bob': {'global': {'1': {'id': '1', 'cursor': 1}}}},
    'user_account_events': {'alice': {'1': {'cursor': 1}}},
}}
assert request('PUT', '', seed)[0] == 200
alice, bob, eve = jwt('alice'), jwt('bob'), jwt('eve')
check('GET', 'chat_v1/user_messages/alice/global', 401)
check('GET', 'chat_v1/user_messages/alice/global', 200, token=alice)
check('GET', 'chat_v1/user_messages/alice/global', 401, token=bob)
check('GET', 'chat_v1/user_messages/eve/global', 401, token=eve)
check('GET', 'chat_v1/user_messages/alice/global', 401, token=jwt('alice', 'revoked'))
check('PUT', 'chat_v1/rooms/global/messages/2', 401, {'body': 'bypass'}, alice)
check('PUT', 'chat_v1/rooms/global/members/eve', 401, {'active': True}, alice)
check('PUT', 'chat_v1/access/alice/s', 401, {'expires_at': now + 9999999}, alice)
typing = {'user_id': 'alice', 'name': 'Alice', 'sequence': 1, 'expires_at': int(time.time() * 1000) + 6000}
check('PUT', 'chat_v1/room_typing/global/alice_session', 200, typing, alice)
check('GET', 'chat_v1/room_typing/global', 200, token=bob)
check('GET', 'chat_v1/room_typing/global', 401, token=eve)
check('PUT', 'chat_v1/room_typing/global/alice_session', 401, {**typing, 'user_id': 'bob'}, alice)
check('PUT', 'chat_v1/room_typing/global/alice_session', 401, {**typing, 'name': 'Admin'}, alice)
check('PUT', 'chat_v1/room_typing/global/alice_session', 401, {**typing, 'sequence': 0}, alice)
check('PUT', 'chat_v1/room_typing/global/alice_session', 401, {**typing, 'expires_at': now + 999999}, alice)
check('PUT', 'chat_v1/room_typing/global/alice_session', 401, {**typing, 'is_admin': True}, alice)
check('DELETE', 'chat_v1/room_typing/global/alice_session', 401, token=bob)
check('DELETE', 'chat_v1/room_typing/global/alice_session', 200, token=alice)
request('PUT', 'chat_v1/rooms/global/members/alice/active', False)
check('GET', 'chat_v1/user_messages/alice/global', 401, token=alice)
request('PUT', 'chat_v1/rooms/global/members/alice/active', True)
request('PUT', 'chat_v1/rooms/global/state/expires_at', now - 1)
check('GET', 'chat_v1/user_messages/alice/global', 401, token=alice)
request('DELETE', 'chat_v1/rooms/global/state/expires_at')
request('PUT', 'chat_v1/access/alice/s/expires_at', now - 1)
check('GET', 'chat_v1/user_messages/alice/global', 401, token=alice)
print('RTDB rules: 22 access and typing checks passed')
