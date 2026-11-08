<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use Illuminate\Database\Seeder;

class LegalPageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            ['slug' => 'terms-and-conditions', 'eyebrow' => 'Legal documentation', 'title' => 'Terms & Conditions', 'effective_date' => 'Effective August 1, 2023 · Version 1.2', 'intro' => 'Zo Stream platform i hman dan tur leh nangma responsibility te hetah hian kan sawi fiah e.', 'sections' => [
                ['heading' => 'Eligibility & accounts', 'body' => 'Account siam tur chuan kum 16 tal i ni tur a ni. Kum 16 hnuailam chuan parent emaw legal guardian enkawlna hnuaiah chauh service an hmang thei.'],
                ['heading' => 'Acceptable use', 'body' => 'Content share phal loh, hacking, spamming, DRM bypass leh screen recording hmanga content lak chhuah te hi khap tlat a ni.'],
                ['heading' => 'Intellectual property', 'body' => 'Zo Stream-a content, software, graphics, logo leh trademarks te chu Zo Stream emaw kan licensors te ta a ni.'],
                ['heading' => 'Payments & subscriptions', 'body' => 'Plan, duration leh device limit i thlan ang kha i pawm tihna a ni. Price tihdanglam a nih chuan advance notice kan pe ang.'],
                ['heading' => 'Law & jurisdiction', 'body' => 'Dispute awm apiang chu Mizoram, India court jurisdiction hnuaiah a awm ang.'],
            ]],
            ['slug' => 'privacy-policy', 'eyebrow' => 'Your privacy', 'title' => 'Privacy Policy', 'effective_date' => 'Last updated August 2026', 'intro' => 'I data kan collect, kan hman leh kan vawn him dan transparent taka i hriat theih nan.', 'sections' => [
                ['heading' => 'Information we collect', 'body' => 'Account details, phone number, device information, payment reference leh viewing activity mamawh chauh kan collect.'],
                ['heading' => 'How we use information', 'body' => 'Login, subscription, playback, support, fraud prevention leh service improvement atan data kan hmang.'],
                ['heading' => 'Sharing & processors', 'body' => 'Payment gateway, cloud hosting leh analytics providers kan mamawh te nen limited data kan share thei. I personal data kan hralh lo.'],
                ['heading' => 'Security & retention', 'body' => 'Data transit encryption leh access controls kan hmang. Legal leh operational mamawhna chinah chauh data kan retain.'],
                ['heading' => 'Your choices', 'body' => 'I profile update, marketing preference thlak leh account/data delete request i thei.'],
            ]],
            ['slug' => 'refund-and-cancellation', 'eyebrow' => 'Payment terms', 'title' => 'Refund & Cancellation', 'effective_date' => 'Effective August 1, 2023', 'intro' => 'Payment dik lo emaw technical problem avanga service hman theih loh chuan kan support team-in fair takin a enfel ang.', 'sections' => [
                ['heading' => 'Digital content policy', 'body' => 'Streaming tan tawh subscription leh PPV purchases te chu digital content an nih avangin generally non-refundable an ni.'],
                ['heading' => 'Subscription cancellation', 'body' => 'Account settings atangin engtik lai pawhin subscription i cancel thei. Access chu current billing period tawp thleng a awm ang.'],
                ['heading' => 'Technical issues', 'body' => 'Zo Stream lama technical problem avanga content hman theih loh chuan 24 hours chhungin support team hriattir rawh.'],
                ['heading' => 'Duplicate payments', 'body' => 'Payment gateway error avanga double charge a awm chuan verification hnuah duplicate amount chu working days 7–10 chhungin kan refund ang.'],
            ]],
            ['slug' => 'return-policy', 'eyebrow' => 'Consumer rights', 'title' => 'Return & Refund', 'effective_date' => 'Effective February 24, 2024', 'intro' => 'Zo Stream hi digital service a nih avangin physical product return ang chi a awm lo.', 'sections' => [
                ['heading' => 'Immediate access notice', 'body' => 'Cancellation i request veleh premium access tawp thei a nih avangin cancel hmaa i decision confirm phawt rawh.'],
                ['heading' => 'Non-refundable services', 'body' => 'Subscription leh PPV payments te chu generally final an ni; applicable law emaw policy exception-in a phal chauh refund a ni.'],
                ['heading' => 'Technical issue exceptions', 'body' => 'Major technical issue avanga service rei tak hman theih loh chuan unused portion atan refund request i siam thei.'],
                ['heading' => 'Processing', 'body' => 'Approved request chu working days 7–10 chhungin process a ni.'],
            ]],
            ['slug' => 'shipping-policy', 'eyebrow' => 'Digital delivery', 'title' => 'Shipping Policy', 'effective_date' => 'Last updated August 2026', 'intro' => 'Zo Stream hi digital streaming service a ni; physical parcel kan ship lo.', 'sections' => [
                ['heading' => 'Instant digital access', 'body' => 'Payment successful hnuah subscription emaw PPV access chu account-ah automatic-in activate a ni.'],
                ['heading' => 'No physical shipping', 'body' => 'DVD, card emaw physical merchandise kan deliver lo. Shipping charge pawh a awm lo.'],
                ['heading' => 'Delivery problems', 'body' => 'Payment success mahse access activate lo a nih chuan payment reference nen support team be rawh.'],
            ]],
            ['slug' => 'copyright-policy', 'eyebrow' => 'Creator protection', 'title' => 'Copyright Policy', 'effective_date' => 'Last updated August 2026', 'intro' => 'Creators leh rights holders te ta kan zah a, unauthorized distribution lakah content kan veng.', 'sections' => [
                ['heading' => 'Protected content', 'body' => 'Zo Stream-a films, series, music, artwork, software leh brand assets te chu copyright laws-in a humhalh.'],
                ['heading' => 'Prohibited activity', 'body' => 'Download phal loh, record, copy, rebroadcast, sell emaw public platform-a upload te hi khap a ni.'],
                ['heading' => 'Copyright complaint', 'body' => 'I content phalna lova Zo Stream-ah a awm i ring chuan ownership proof, content URL leh contact details nen notice min rawn thawn rawh.'],
                ['heading' => 'Review process', 'body' => 'Complete notice kan dawn hnuah kan investigate ang a, a tul chuan content remove emaw access restrict kan ti ang.'],
            ]],
        ];

        foreach ($pages as $sortOrder => $page) {
            LegalPage::updateOrCreate(
                ['slug' => $page['slug']],
                [...$page, 'is_published' => true, 'sort_order' => $sortOrder]
            );
        }
    }
}
