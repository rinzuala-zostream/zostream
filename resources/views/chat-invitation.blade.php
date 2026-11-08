<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<title>Zo Stream Invitation</title>
<style>body{margin:0;background:#10151f;color:#fff;font:17px system-ui;display:grid;min-height:100dvh;place-items:center}main{max-width:420px;padding:32px}a{display:block;padding:14px 20px;margin:16px 0;border-radius:12px;background:#2463eb;color:white;text-align:center;text-decoration:none}p{line-height:1.6;color:#cbd5e1}</style>
</head><body><main><h1>Zo Stream Invitation</h1><p>Open this invitation in Zo Stream. Sign in with the phone number that received the invitation.</p>
@php
    $invitationUrl = rtrim(config('chat.invitation_url'), '/').'/'.rawurlencode($token);
    $androidUrl = 'intent://app.zostream.in/invite/'.rawurlencode($token)
        .'#Intent;scheme=https;package=com.buannel.studio.pvt.ltd.zostream;S.browser_fallback_url='
        .rawurlencode('https://zostream.in/download').';end';
    $iosUrl = 'zostream://invite/'.rawurlencode($token);
@endphp
<a id="open-invitation" href="{{ $invitationUrl }}" data-android="{{ $androidUrl }}" data-ios="{{ $iosUrl }}">Open in Zo Stream</a>
<a href="https://zostream.in/download">Get the Zo Stream app</a>
<p>If the app does not open, install or update Zo Stream, then return here and tap Open in Zo Stream. You can also open the link in your phone’s browser from WhatsApp’s menu.</p>
<p>Only the invited account can view or join the room.</p></main>
<script>
const openInvitation = document.getElementById('open-invitation');
const userAgent = navigator.userAgent;
if (/Android/i.test(userAgent)) {
    openInvitation.href = openInvitation.dataset.android;
} else if (/iPhone|iPad|iPod/i.test(userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
    openInvitation.href = openInvitation.dataset.ios;
}
</script>
</body></html>
