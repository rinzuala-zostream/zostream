-- Zo Stream legal-pages CMS schema and initial content
-- Compatible with MySQL 5.7+/8.x and MariaDB 10.2+

CREATE TABLE IF NOT EXISTS `legal_pages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(120) NOT NULL,
    `eyebrow` VARCHAR(160) NULL,
    `title` VARCHAR(180) NOT NULL,
    `effective_date` VARCHAR(180) NULL,
    `intro` TEXT NULL,
    `sections` JSON NOT NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `legal_pages_slug_unique` (`slug`),
    KEY `legal_pages_is_published_index` (`is_published`),
    KEY `legal_pages_sort_order_index` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `legal_pages`
    (`slug`, `eyebrow`, `title`, `effective_date`, `intro`, `sections`, `is_published`, `sort_order`)
VALUES
(
    'terms-and-conditions',
    'Legal documentation',
    'Terms & Conditions',
    'Effective August 1, 2023 · Version 1.2',
    'Zo Stream platform i hman dan tur leh nangma responsibility te hetah hian kan sawi fiah e.',
    JSON_ARRAY(
        JSON_OBJECT('heading', 'Eligibility & accounts', 'body', 'Account siam tur chuan kum 16 tal i ni tur a ni. Kum 16 hnuailam chuan parent emaw legal guardian enkawlna hnuaiah chauh service an hmang thei.'),
        JSON_OBJECT('heading', 'Acceptable use', 'body', 'Content share phal loh, hacking, spamming, DRM bypass leh screen recording hmanga content lak chhuah te hi khap tlat a ni.'),
        JSON_OBJECT('heading', 'Intellectual property', 'body', 'Zo Stream-a content, software, graphics, logo leh trademarks te chu Zo Stream emaw kan licensors te ta a ni.'),
        JSON_OBJECT('heading', 'Payments & subscriptions', 'body', 'Plan, duration leh device limit i thlan ang kha i pawm tihna a ni. Price tihdanglam a nih chuan advance notice kan pe ang.'),
        JSON_OBJECT('heading', 'Law & jurisdiction', 'body', 'Dispute awm apiang chu Mizoram, India court jurisdiction hnuaiah a awm ang.')
    ),
    1,
    0
),
(
    'privacy-policy',
    'Your privacy',
    'Privacy Policy',
    'Last updated August 2026',
    'I data kan collect, kan hman leh kan vawn him dan transparent taka i hriat theih nan.',
    JSON_ARRAY(
        JSON_OBJECT('heading', 'Information we collect', 'body', 'Account details, phone number, device information, payment reference leh viewing activity mamawh chauh kan collect.'),
        JSON_OBJECT('heading', 'How we use information', 'body', 'Login, subscription, playback, support, fraud prevention leh service improvement atan data kan hmang.'),
        JSON_OBJECT('heading', 'Sharing & processors', 'body', 'Payment gateway, cloud hosting leh analytics providers kan mamawh te nen limited data kan share thei. I personal data kan hralh lo.'),
        JSON_OBJECT('heading', 'Security & retention', 'body', 'Data transit encryption leh access controls kan hmang. Legal leh operational mamawhna chinah chauh data kan retain.'),
        JSON_OBJECT('heading', 'Your choices', 'body', 'I profile update, marketing preference thlak leh account/data delete request i thei.')
    ),
    1,
    1
),
(
    'refund-and-cancellation',
    'Payment terms',
    'Refund & Cancellation',
    'Effective August 1, 2023',
    'Payment dik lo emaw technical problem avanga service hman theih loh chuan kan support team-in fair takin a enfel ang.',
    JSON_ARRAY(
        JSON_OBJECT('heading', 'Digital content policy', 'body', 'Streaming tan tawh subscription leh PPV purchases te chu digital content an nih avangin generally non-refundable an ni.'),
        JSON_OBJECT('heading', 'Subscription cancellation', 'body', 'Account settings atangin engtik lai pawhin subscription i cancel thei. Access chu current billing period tawp thleng a awm ang.'),
        JSON_OBJECT('heading', 'Technical issues', 'body', 'Zo Stream lama technical problem avanga content hman theih loh chuan 24 hours chhungin support team hriattir rawh.'),
        JSON_OBJECT('heading', 'Duplicate payments', 'body', 'Payment gateway error avanga double charge a awm chuan verification hnuah duplicate amount chu working days 7–10 chhungin kan refund ang.')
    ),
    1,
    2
),
(
    'return-policy',
    'Consumer rights',
    'Return & Refund',
    'Effective February 24, 2024',
    'Zo Stream hi digital service a nih avangin physical product return ang chi a awm lo.',
    JSON_ARRAY(
        JSON_OBJECT('heading', 'Immediate access notice', 'body', 'Cancellation i request veleh premium access tawp thei a nih avangin cancel hmaa i decision confirm phawt rawh.'),
        JSON_OBJECT('heading', 'Non-refundable services', 'body', 'Subscription leh PPV payments te chu generally final an ni; applicable law emaw policy exception-in a phal chauh refund a ni.'),
        JSON_OBJECT('heading', 'Technical issue exceptions', 'body', 'Major technical issue avanga service rei tak hman theih loh chuan unused portion atan refund request i siam thei.'),
        JSON_OBJECT('heading', 'Processing', 'body', 'Approved request chu working days 7–10 chhungin process a ni.')
    ),
    1,
    3
),
(
    'shipping-policy',
    'Digital delivery',
    'Shipping Policy',
    'Last updated August 2026',
    'Zo Stream hi digital streaming service a ni; physical parcel kan ship lo.',
    JSON_ARRAY(
        JSON_OBJECT('heading', 'Instant digital access', 'body', 'Payment successful hnuah subscription emaw PPV access chu account-ah automatic-in activate a ni.'),
        JSON_OBJECT('heading', 'No physical shipping', 'body', 'DVD, card emaw physical merchandise kan deliver lo. Shipping charge pawh a awm lo.'),
        JSON_OBJECT('heading', 'Delivery problems', 'body', 'Payment success mahse access activate lo a nih chuan payment reference nen support team be rawh.')
    ),
    1,
    4
),
(
    'copyright-policy',
    'Creator protection',
    'Copyright Policy',
    'Last updated August 2026',
    'Creators leh rights holders te ta kan zah a, unauthorized distribution lakah content kan veng.',
    JSON_ARRAY(
        JSON_OBJECT('heading', 'Protected content', 'body', 'Zo Stream-a films, series, music, artwork, software leh brand assets te chu copyright laws-in a humhalh.'),
        JSON_OBJECT('heading', 'Prohibited activity', 'body', 'Download phal loh, record, copy, rebroadcast, sell emaw public platform-a upload te hi khap a ni.'),
        JSON_OBJECT('heading', 'Copyright complaint', 'body', 'I content phalna lova Zo Stream-ah a awm i ring chuan ownership proof, content URL leh contact details nen notice min rawn thawn rawh.'),
        JSON_OBJECT('heading', 'Review process', 'body', 'Complete notice kan dawn hnuah kan investigate ang a, a tul chuan content remove emaw access restrict kan ti ang.')
    ),
    1,
    5
)
ON DUPLICATE KEY UPDATE
    `eyebrow` = VALUES(`eyebrow`),
    `title` = VALUES(`title`),
    `effective_date` = VALUES(`effective_date`),
    `intro` = VALUES(`intro`),
    `sections` = VALUES(`sections`),
    `is_published` = VALUES(`is_published`),
    `sort_order` = VALUES(`sort_order`),
    `updated_at` = CURRENT_TIMESTAMP;
