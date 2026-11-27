import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { assertFails, assertSucceeds, initializeTestEnvironment } from '@firebase/rules-unit-testing';
import { get, limitToFirst, limitToLast, orderByChild, orderByKey, query, ref, set, startAt } from 'firebase/database';

const rulesDoc = JSON.parse(await readFile(new URL('../Library/firebase/database.rules.json', import.meta.url), 'utf8'));
const rules = rulesDoc.rules;
const schema = JSON.parse(await readFile(new URL('../Library/firebase/chat-schema-v1.json', import.meta.url), 'utf8'));
const liveDeliverySource = await readFile(new URL('../app/Support/Chat/ChatFirebaseLiveDelivery.php', import.meta.url), 'utf8');
const firebaseSource = await readFile(new URL('../app/Support/Chat/ChatFirebase.php', import.meta.url), 'utf8');

test('chat v1 is deny by default and preserves existing Firebase paths', () => {
    assert.equal(rules['.read'], false);
    assert.equal(rules['.write'], false);
    for (const path of ['qr_sessions', 'config', 'warning', 'text_scroll', 'update', 'ios_update', 'tv_update', 'lg_tv_update', 'sam_tv_update', 'windows_update', 'macos_update', 'official_client_configs', 'payment_features']) {
        assert.ok(rules[path], `existing Firebase path ${path} remains in the combined rules`);
    }
    assert.equal(rules.windows_update['.read'], true);
    assert.equal(rules.macos_update['.read'], true);
    assert.equal(rules.official_client_configs['.read'], true);
    assert.equal(rules.payment_features.amazon_tv.iap_enabled['.read'], true);
    assert.equal(rules.config.razorpay.key_id['.read'], true);
    assert.equal(rules.config.analytics['.read'], true);
    assert.match(rules.qr_sessions.$token.amount['.write'], /type.*payment/);
});

test('only projected sessions and room permissions grant chat access', () => {
    const chat = rules.chat_v1;
    assert.equal(chat.auth_sessions['.write'], false);
    assert.equal(chat.room_authz['.write'], false);
    assert.equal(chat.sync_events['$room']['.write'], false);
    assert.equal(chat.messages.$room['.read'], false);
    assert.equal(chat.messages.$room.$message['.write'], false);
    assert.equal(chat.reactions.$room.$message.$uid['.write'], false);
    assert.equal(chat.read_receipts.$room.$uid['.write'], false);
    assert.equal(chat.typing.$room.$uid['.write'], false);
    assert.equal(chat.reactions.$room.$message['.read'], false);
    for (const key of ['rooms', 'user_messages', 'user_sync_events', 'user_typing', 'read_receipts']) {
        const serialized = JSON.stringify(chat[key]);
        assert.match(serialized, /auth\.token\.chat_sid/);
        assert.match(serialized, /auth_sessions/);
        assert.match(serialized, /client_access/);
        assert.match(serialized, /room_authz/);
    }
    assert.match(chat.user_account_events.$uid['.read'], /account_events_access/);
    assert.match(chat.user_account_events.$uid['.read'], /query\.limitToLast <= 500/);
    assert.doesNotMatch(chat.user_account_events.$uid['.read'], /room_authz/);
});

test('only trusted Laravel processing can publish validated messages', () => {
    const messageRules = rules.chat_v1.messages.$room.$message;
    assert.equal(messageRules['.write'], false);
    assert.equal(messageRules['.read'], false);
    assert.equal(schema.version, 1);
    assert.deepEqual(schema.paths['messages/{roomId}/{messageId}'].message_types, ['text', 'movie_suggestion', 'room_invitation', 'watch_invitation']);
    assert.deepEqual(schema.paths['messages/{roomId}/{messageId}'].client_create_fields, []);
});

test('message and sync reads have bounded query limits', () => {
    assert.match(rules.chat_v1.user_rooms.$uid['.read'], /query\.limitToLast <= 200/);
    assert.match(rules.chat_v1.user_messages.$uid.$room['.read'], /query\.limitToLast <= 100/);
    assert.match(rules.chat_v1.user_sync_events.$uid.$room['.read'], /query\.limitToLast <= 500/);
    assert.match(rules.chat_v1.user_sync_events.$uid.$room['.read'], /query\.limitToFirst <= 100/);
    assert.match(rules.chat_v1.user_sync_events.$uid.$room['.read'], /query\.startAt != null/);
    assert.match(rules.chat_v1.user_typing.$uid.$room['.read'], /query\.limitToLast <= 20/);
    assert.match(rules.chat_v1.user_typing.$uid.$room.$typist['.validate'], /now \+ 30000/);
});

test('server fanout applies the current block list and block changes clear Firebase access/data', () => {
    assert.match(liveDeliverySource, /where\('blocked_user_id', \$message->user_id\)/);
    assert.match(firebaseSource, /client_access.*false/);
    assert.match(firebaseSource, /user_messages\/\$uid.*=> null/);
    assert.match(firebaseSource, /user_sync_events\/\$uid.*=> null/);
});

test('Firebase Realtime Database emulator authorization behavior', async (t) => {
    const host = process.env.FIREBASE_DATABASE_EMULATOR_HOST;
    if (!host) return t.skip('Set FIREBASE_DATABASE_EMULATOR_HOST to run the Firebase rules behavior tests.');

    const [emulatorHost, rawPort] = host.split(':');
    const environment = await initializeTestEnvironment({
        projectId: 'demo-zostream-chat',
        database: { host: emulatorHost, port: Number(rawPort), rules: JSON.stringify(rulesDoc) },
    });
    const uid = 'zs_fixture_user';
    const sid = 'fixture-session';
    const now = Date.now();
    const fixture = {
        auth_sessions: { [uid]: { [sid]: { active: true, client_access: true, account_events_access: true, expires_at: now + 60_000 }, 'other-session': { active: true, client_access: true, account_events_access: true, expires_at: now + 60_000 } } },
        room_authz: { 17: { [uid]: { [sid]: { can_read: true, can_write: true, read_until: now + 60_000, write_until: now + 60_000 } } } },
        user_messages: { [uid]: { 17: {
            '-OfixtureMessage0001': { message_type: 'text', sender_uid: uid, sender_name: 'Fixture User', body: 'seed', spoiler: false, client_op_id: 'seed-op', created_at: now, revision: 1, deleted: false, cursor: 1 }
        } } },
        user_typing: { [uid]: { 17: {
            zs_typer: { typist_uid: 'zs_typer', name: 'Typing User', is_admin: false, expires_at: now + 5_000 }
        } } },
        user_sync_events: { [uid]: { 17: {
            '00000000000000000001': { cursor: 1, kind: 'created' },
            '00000000000000000002': { cursor: 2, kind: 'created' }
        } } },
        user_account_events: { [uid]: {
            '00000000000000000001': { revision: '1', kind: 'unread_invalidated', server_at: now },
            '00000000000000000002': { revision: '2', kind: 'room_state_invalidated', server_at: now }
        } }
    };
    try {
        await environment.withSecurityRulesDisabled(async (context) => {
            await set(ref(context.database(), 'chat_v1'), fixture);
            await set(ref(context.database(), 'qr_sessions/fixture-payment'), {
                type: 'payment', expires_at: Math.floor(Date.now() / 1000) + 60, amount: 1,
            });
            await set(ref(context.database(), 'warning'), 'fixture warning');
        });
        const unauthenticated = environment.unauthenticatedContext().database();
        await assertSucceeds(get(ref(unauthenticated, 'warning')));
        await assertSucceeds(set(ref(unauthenticated, 'qr_sessions/fixture-payment/amount'), 2));
        const member = environment.authenticatedContext(uid, { chat_sid: sid });
        const stranger = environment.authenticatedContext('zs_stranger', { chat_sid: sid });
        const secondSession = environment.authenticatedContext(uid, { chat_sid: 'other-session' });
        const db = member.database();
        const messages = ref(db, `chat_v1/user_messages/${uid}/17`);
        const canonicalMessages = ref(db, 'chat_v1/messages/17');
        await environment.withSecurityRulesDisabled(async (context) => {
            await set(ref(context.database(), `chat_v1/auth_sessions/${uid}/${sid}/client_access`), false);
        });
        await assertFails(get(query(messages, orderByKey(), limitToLast(30))));
        await environment.withSecurityRulesDisabled(async (context) => {
            await set(ref(context.database(), `chat_v1/auth_sessions/${uid}/${sid}/client_access`), true);
        });
        const history = await assertSucceeds(get(query(messages, orderByKey(), limitToLast(30))));
        assert.equal(history.child('-OfixtureMessage0001').child('body').val(), 'seed', 'test context reads the seeded namespace');
        const liveMessages = await assertSucceeds(get(query(messages, orderByChild('cursor'), startAt(2), limitToLast(30))));
        assert.equal(liveMessages.exists(), false, 'opening a room does not download messages at or below its cursor');
        const liveEvents = await assertSucceeds(get(query(ref(db, `chat_v1/user_sync_events/${uid}/17`), orderByChild('cursor'), startAt(2), limitToLast(100))));
        assert.deepEqual(Object.keys(liveEvents.val() ?? {}), ['00000000000000000002']);
        const typing = await assertSucceeds(get(query(ref(db, `chat_v1/user_typing/${uid}/17`), orderByKey(), limitToLast(20))));
        assert.equal(typing.child('zs_typer').child('name').val(), 'Typing User');
        const incrementalEvents = await assertSucceeds(get(query(ref(db, `chat_v1/user_sync_events/${uid}/17`), orderByKey(), startAt('00000000000000000002'), limitToFirst(100))));
        assert.ok(Object.keys(incrementalEvents.val() ?? {}).length >= 1, 'saved cursors can resume at the next event without rereading the tail');
        assert.equal(incrementalEvents.child('00000000000000000002').child('cursor').val(), 2);
        await assertFails(get(query(ref(db, `chat_v1/user_sync_events/${uid}/17`), orderByKey(), startAt('00000000000000000002'), limitToFirst(101))));
        const accountEvents = ref(db, `chat_v1/user_account_events/${uid}`);
        const badgeHistory = await assertSucceeds(get(query(accountEvents, orderByKey(), limitToLast(500))));
        assert.equal(badgeHistory.child('00000000000000000001').child('kind').val(), 'unread_invalidated');
        const badgeResume = await assertSucceeds(get(query(accountEvents, orderByKey(), startAt('00000000000000000002'), limitToFirst(100))));
        assert.equal(badgeResume.child('00000000000000000002').exists(), true);
        await assertFails(get(ref(db, `chat_v1/user_typing/${uid}/17`)));
        await assertFails(set(ref(db, `chat_v1/user_typing/${uid}/17/zs_forged`), {
            typist_uid: 'zs_forged', name: 'Forged', is_admin: false, expires_at: Date.now() + 5_000,
        }));
        await assertFails(get(messages));
        await assertFails(get(query(ref(stranger.database(), `chat_v1/user_messages/${uid}/17`), orderByKey(), limitToLast(30))));
        await assertFails(get(query(ref(secondSession.database(), `chat_v1/user_messages/${uid}/17`), orderByKey(), limitToLast(30))));
        await assertFails(get(query(canonicalMessages, orderByKey(), limitToLast(30))));

        const ownMessage = { message_type: 'text', sender_uid: uid, body: 'hello', spoiler: false, client_op_id: 'send-op', created_at: Date.now(), revision: 1, deleted: false };
        await assertFails(set(ref(db, 'chat_v1/messages/17/-OfixtureMessage0002'), ownMessage));
        await assertFails(set(ref(db, 'chat_v1/user_messages/zs_other/17/forged'), ownMessage));
        await assertFails(set(ref(db, 'chat_v1/user_sync_events/zs_other/17/forged'), { cursor: 999, kind: 'created' }));

        await assertFails(set(ref(db, `chat_v1/reactions/17/-OfixtureMessage0001/${uid}`), { emoji: '😂', updated_at: Date.now() }));
        await assertFails(set(ref(db, `chat_v1/reactions/17/-OfixtureMessage0001/${uid}`), { emoji: '💣', updated_at: Date.now() }));
        await assertFails(set(ref(db, 'chat_v1/reactions/17/-OfixtureMessage0001/zs_other'), { emoji: '😂', updated_at: Date.now() }));

        await environment.withSecurityRulesDisabled(async (context) => {
            await set(ref(context.database(), `chat_v1/room_authz/17/${uid}/${sid}/read_until`), now - 1);
        });
        await assertFails(get(query(messages, orderByKey(), limitToLast(30))));
        await environment.withSecurityRulesDisabled(async (context) => {
            await set(ref(context.database(), `chat_v1/room_authz/17/${uid}/${sid}/read_until`), now + 60_000);
            await set(ref(context.database(), `chat_v1/auth_sessions/${uid}/${sid}/active`), false);
        });
        await assertFails(get(query(messages, orderByKey(), limitToLast(30))));
    } finally {
        await environment.cleanup();
    }
});
