import { test, afterEach, mock } from 'node:test';
import assert from 'node:assert/strict';
import { notify, toasts, clearToasts, dismissToast, pauseToast, resumeToast, confirmAction, confirmation, resolveConfirmation, cancelConfirmations } from '../../resources/js/admin/lib/feedback.js';

afterEach(() => { clearToasts(); cancelConfirmations(); mock.timers.reset(); });

test('feedback deduplicates matching messages and keeps the newest five visible', () => {
    assert.equal(notify(''), null);
    const first = notify('Saved.', 'success');
    assert.equal(notify('Saved.', 'success'), first);
    assert.equal(toasts.length, 1);
    for (let i = 0; i < 5; i++) notify(`Error ${i}`, 'error');
    assert.equal(toasts.length, 5);
    assert.ok(!toasts.some(item => item.id === first));
    dismissToast(toasts[0].id); assert.equal(toasts.length, 4);
});

test('success expires after readable time and pauses while being read; errors and warnings persist', () => {
    mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const success = notify('Saved.', 'success');
    const error = notify('Save failed.', 'error'); const warning = notify('Check this field.', 'warning');
    mock.timers.tick(3000); pauseToast(success);
    mock.timers.tick(20000); assert.ok(toasts.some(item => item.id === success));
    resumeToast(success); mock.timers.tick(3499);
    assert.ok(toasts.some(item => item.id === success));
    mock.timers.tick(1); assert.ok(!toasts.some(item => item.id === success));
    mock.timers.tick(60000);
    assert.deepEqual(toasts.map(item => item.id), [error, warning]);
});

test('confirmation gates actions and rejects duplicate clicks without duplicate writes', async () => {
    let writes = 0;
    const request = confirmAction('Delete this record?').then(approved => { if (approved) writes++; return approved; });
    assert.equal(writes, 0);
    assert.equal(await confirmAction('Delete this record?'), false);
    resolveConfirmation(true, -1); assert.equal(writes, 0);
    resolveConfirmation(true); assert.equal(await request, true); assert.equal(writes, 1);
    assert.equal(confirmation.value, null);
});

test('Cancel, Escape and leaving a page resolve pending actions as false', async () => {
    const first = confirmAction('Suspend user?');
    const second = confirmAction('Delete record?');
    const firstId = confirmation.value.id;
    resolveConfirmation(false); assert.equal(await first, false);
    assert.equal(confirmation.value.message, 'Delete record?');
    resolveConfirmation(true, firstId); assert.equal(confirmation.value.message, 'Delete record?');
    cancelConfirmations(); assert.equal(await second, false); assert.equal(confirmation.value, null);
});

test('a modal or overlapping hover and focus cannot expire a toast while it is being read', async () => {
    mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const id = notify('Changes saved.', 'success');
    pauseToast(id, 'hover'); pauseToast(id, 'focus');
    resumeToast(id, 'hover'); mock.timers.tick(10000);
    assert.ok(toasts.some(item => item.id === id));
    resumeToast(id, 'focus');
    const approval = confirmAction('Continue?'); mock.timers.tick(10000);
    assert.ok(toasts.some(item => item.id === id));
    resolveConfirmation(false); await approval;
    mock.timers.tick(6500); assert.equal(toasts.length, 0);
});
