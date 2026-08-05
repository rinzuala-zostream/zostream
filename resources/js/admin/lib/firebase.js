import { getApp, getApps, initializeApp } from 'firebase/app';
import { getDatabase, ref } from 'firebase/database';

const appName = 'zostream-vue-admin';

function databaseUrl() {
    const value = window.__ZOSTREAM_ADMIN__?.firebaseDatabaseUrl;
    if (!value) throw new Error('Firebase Realtime Database URL is not configured.');
    return value;
}

function firebaseApp() {
    return getApps().some((app) => app.name === appName)
        ? getApp(appName)
        : initializeApp({ databaseURL: databaseUrl() }, appName);
}

export function qrSessionRef(token) {
    return ref(getDatabase(firebaseApp()), `qr_sessions/${token}`);
}
