import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { XMLParser } from 'fast-xml-parser';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ---------- CLI ----------
const inputArg = process.argv[2];
const outDir = process.argv[3] || path.join(__dirname, 'out_hls');
if (!inputArg) {
  console.error('Usage: node mpd-to-hls.js /path/or/url/to/{manifest|stream}.mpd ./out_hls');
  process.exit(1);
}

fs.mkdirSync(outDir, { recursive: true });

// ---------- Helpers ----------
const isHttpUrl = (s) => /^https?:\/\//i.test(s);
const arr = (v) => (v == null ? [] : Array.isArray(v) ? v : [v]);

const parser = new XMLParser({
  ignoreAttributes: false,
  attributeNamePrefix: '',
});

function escUri(u) {
  return u.replace(/ /g, '%20');
}

// URL join that respects absolute URLs / roots
function joinUrl(base, relative) {
  if (!relative) return base || '';
  if (isHttpUrl(relative)) return relative;
  if (relative.startsWith('/')) {
    // If base is URL, keep protocol + host
    if (isHttpUrl(base)) {
      const url = new URL(base);
      return `${url.protocol}//${url.host}${relative}`;
    }
    return relative; // filesystem path-like
  }
  if (!base) return relative;

  if (isHttpUrl(base)) {
    const url = new URL(base);
    // Ensure single slash
    const merged = [url.pathname.replace(/\/+$/,'') , relative.replace(/^\/+/,'')].join('/');
    url.pathname = merged;
    return url.toString();
  } else {
    // POSIX join for file paths
    return path.posix.join(base, relative);
  }
}

function secs(value, timescale) {
  return Number(value) / Number(timescale || 1);
}

function parseISODurationToSeconds(iso) {
  // Very tiny parser for PTxxS style durations
  if (!iso || typeof iso !== 'string') return null;
  // Support hours/minutes/seconds
  const m = iso.match(/P(?:\d+Y)?(?:\d+M)?(?:\d+D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?/);
  if (!m) return null;
  const h = Number(m[1] || 0);
  const min = Number(m[2] || 0);
  const s = Number(m[3] || 0);
  return h * 3600 + min * 60 + s;
}

function chooseDeepestBaseURL(mpdBase, periodBase, asBase, repBase) {
  // The deepest BaseURL overrides above levels
  return repBase ?? asBase ?? periodBase ?? mpdBase ?? '';
}

function normalizeToArray(objOrArray) {
  if (objOrArray == null) return [];
  return Array.isArray(objOrArray) ? objOrArray : [objOrArray];
}

// Build segments for either $Number$ or $Time$ pattern
function buildSegmentsFromTemplate({ tpl, mpdDurationSec, timescaleDefault = 1 }) {
  const timescale = Number(tpl.timescale || timescaleDefault || 1);
  const Slist = arr(tpl.SegmentTimeline?.S);
  const mediaPattern = tpl.media;
  const startNumber = Number(tpl.startNumber || 1);
  const hasTimeVar = /\$Time\$/i.test(mediaPattern);
  const hasNumberVar = /\$Number\$/i.test(mediaPattern);

  const items = [];

  if (Slist.length) {
    // SegmentTimeline present
    let currentTime = 0;
    let number = startNumber;

    for (let i = 0; i < Slist.length; i++) {
      const S = Slist[i];
      const d = Number(S.d);
      const r = Number(S.r ?? 0);
      // S.t, if present, sets the start time for the FIRST of this run
      if (S.t != null) currentTime = Number(S.t);

      const reps = r + 1;
      for (let k = 0; k < reps; k++) {
        const durationSec = secs(d, timescale);

        let uri;
        if (hasTimeVar) {
          uri = mediaPattern.replace(/\$Time\$/gi, String(currentTime));
        } else if (hasNumberVar) {
          uri = mediaPattern.replace(/\$Number\$/gi, String(number));
        } else {
          // No variable? Unusual but fallback to raw pattern
          uri = mediaPattern;
        }

        items.push({ duration: durationSec, uri });

        // Advance counters
        currentTime += d;
        number += 1;
      }
    }
  } else if (tpl.duration) {
    // No SegmentTimeline: constant duration segments using $Number$
    const d = Number(tpl.duration);
    const durationSec = secs(d, timescale);

    // Decide how many segments
    let count = Number(process.env.SEG_COUNT || 500);
    if (mpdDurationSec && durationSec > 0) {
      // Try to estimate count from presentation duration
      count = Math.max(1, Math.ceil(mpdDurationSec / durationSec));
    }

    for (let i = 0; i < count; i++) {
      let uri;
      if (hasNumberVar) {
        uri = mediaPattern.replace(/\$Number\$/gi, String(startNumber + i));
      } else if (hasTimeVar) {
        // If $Time$ but no timeline, synthesize time stamps
        const t = d * i;
        uri = mediaPattern.replace(/\$Time\$/gi, String(t));
      } else {
        uri = mediaPattern;
      }
      items.push({ duration: durationSec, uri });
    }
  }

  return { items, timescale };
}

function makeMediaPlaylist({ initUrlAbs, segUrlsAbs, items, playlistType = 'VOD', targetPad = 0 }) {
  const target = Math.max(
    ...items.map(s => Math.ceil(s.duration || 0.001)),
    1
  ) + Number(process.env.TARGET_DURATION_PAD || targetPad || 0);

  const lines = [
    '#EXTM3U',
    '#EXT-X-VERSION:7',
    `#EXT-X-TARGETDURATION:${target}`,
    '#EXT-X-MEDIA-SEQUENCE:0',
    `#EXT-X-PLAYLIST-TYPE:${playlistType}`,
    `#EXT-X-MAP:URI="${escUri(initUrlAbs)}"`,
  ];

  for (let i = 0; i < items.length; i++) {
    const seg = items[i];
    lines.push(`#EXTINF:${seg.duration.toFixed(3)},`);
    lines.push(escUri(segUrlsAbs[i]));
  }
  lines.push('#EXT-X-ENDLIST');
  return lines.join('\n');
}

function repIsAudio(as, rep) {
  return (as.contentType === 'audio') ||
         (rep.audioSamplingRate != null) ||
         (as.mimeType && as.mimeType.startsWith('audio/')) ||
         (rep.mimeType && rep.mimeType.startsWith('audio/'));
}

// ---------- Load MPD (local or URL) ----------
async function loadMpdText(src) {
  if (isHttpUrl(src)) {
    const res = await fetch(src);
    if (!res.ok) throw new Error(`Failed to fetch MPD: ${res.status} ${res.statusText}`);
    return await res.text();
  } else {
    return fs.readFileSync(src, 'utf-8');
  }
}

function deriveBaseFromInput(input) {
  if (isHttpUrl(input)) {
    // e.g. https://cdn/foo/bar/stream.mpd?token=abc#frag => https://cdn/foo/bar/
    const u = new URL(input);
    // Remove last path segment (whatever it is: manifest.mpd, stream.mpd, etc.)
    const parts = u.pathname.split('/');
    parts.pop(); // drop last component
    u.pathname = parts.join('/') + (parts.length > 1 ? '/' : '/');
    u.search = ''; // base shouldn't carry the MPD's query string
    u.hash = '';
    return u.toString();
  } else {
    // local file path base
    const dir = path.resolve(path.dirname(input)).replace(/\\/g, '/');
    return dir.endsWith('/') ? dir : dir + '/';
  }
}

// ---------- Main ----------
(async () => {
  try {
    const mpdXml = await loadMpdText(inputArg);
    const mpd = parser.parse(mpdXml).MPD;

    const mpdDurationSec = parseISODurationToSeconds(mpd.mediaPresentationDuration);
    const playlistType = (mpd.type === 'static' ? 'VOD' : 'EVENT');

    // BaseURL can exist at MPD, Period, AdaptationSet, Representation levels
    const mpdBaseArr = normalizeToArray(mpd.BaseURL).map(s => (typeof s === 'string' ? s.trim() : String(s)));
    // If MPD has no BaseURL, derive from the input path/URL
    const inputBase = deriveBaseFromInput(inputArg);
    const mpdBase = mpdBaseArr[0] ? joinUrl(inputBase, mpdBaseArr[0]) : inputBase;

    const periods = arr(mpd.Period);

    const variants = []; // Collect for master.m3u8

    for (const period of periods) {
      const periodBaseArr = normalizeToArray(period.BaseURL).map(String);
      const periodBase = periodBaseArr[0] ? joinUrl(mpdBase, periodBaseArr[0]) : mpdBase;

      const ASets = arr(period.AdaptationSet);
      for (const as of ASets) {
        const asBaseArr = normalizeToArray(as.BaseURL).map(String);
        const asBase = asBaseArr[0] ? joinUrl(periodBase, asBaseArr[0]) : periodBase;

        for (const rep of arr(as.Representation)) {
          const repBaseArr = normalizeToArray(rep.BaseURL).map(String);
          const repBase = repBaseArr[0] ? joinUrl(asBase, repBaseArr[0]) : asBase;

          // Prefer Rep.SegmentTemplate else AS.SegmentTemplate
          const tpl = rep.SegmentTemplate || as.SegmentTemplate;
          if (!tpl || !tpl.media || !tpl.initialization) continue;

          const repId = (rep.id ?? '').toString();

          // Resolve variables in init/media
          const initPattern = tpl.initialization.replace(/\$RepresentationID\$/gi, repId);
          const mediaPattern = tpl.media.replace(/\$RepresentationID\$/gi, repId);

          const { items } = buildSegmentsFromTemplate({
            tpl: { ...tpl, media: mediaPattern },
            mpdDurationSec,
          });

          if (!items.length) {
            console.warn(`Skipping ${repId} — no segments derived`);
            continue;
          }

          // Absolute URLs for init + each segment
          const initAbs = joinUrl(repBase, initPattern);
          const segAbs = items.map(it => joinUrl(repBase, it.uri));

          // Write media playlist
          const isAudio = repIsAudio(as, rep);
          const m3u8Name = `${repId}${isAudio ? '_audio' : '_video'}.m3u8`;

          const playlistText = makeMediaPlaylist({
            initUrlAbs: initAbs,
            segUrlsAbs: segAbs,
            items,
            playlistType,
          });

          fs.writeFileSync(path.join(outDir, m3u8Name), playlistText, 'utf-8');

          variants.push({
            type: isAudio ? 'audio' : 'video',
            id: repId,
            bw: Number(rep.bandwidth || 0),
            res: (rep.width && rep.height) ? `${rep.width}x${rep.height}` : null,
            codecs: rep.codecs || (isAudio ? 'mp4a.40.2' : 'avc1.640028'),
            uri: m3u8Name,
            groupId: isAudio ? `audio-${as.lang || 'und'}` : null,
            lang: as.lang || 'und',
          });

          console.log(`Wrote ${m3u8Name} (${items.length} segments)`);
        }
      }
    }

    // ---------- Build MASTER ----------
    const audioVariants = variants.filter(v => v.type === 'audio');
    const videoVariants = variants.filter(v => v.type === 'video');

    let master = ['#EXTM3U', '#EXT-X-VERSION:7'];

    // Group audio by language to avoid clobbering
    const audioByLang = {};
    for (const a of audioVariants) {
      const g = a.groupId || `audio-${a.lang}`;
      audioByLang[g] = audioByLang[g] || [];
      audioByLang[g].push(a);
    }

    // Emit #EXT-X-MEDIA for each audio rendition
    for (const [groupId, list] of Object.entries(audioByLang)) {
      // Mark the first entry in each group as DEFAULT
      list.forEach((a, idx) => {
        master.push(
          `#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="${groupId}",NAME="${a.lang}",LANGUAGE="${a.lang}",AUTOSELECT=YES,DEFAULT=${idx===0?'YES':'NO'},URI="${a.uri}"`
        );
      });
    }

    // Choose a default audio group if any exist
    const defaultAudioGroup = Object.keys(audioByLang)[0];

    // Emit video variants, referencing a chosen audio group (fallback to the first one if exists)
    for (const v of videoVariants.sort((x, y) => x.bw - y.bw)) {
      const audioGroup = defaultAudioGroup || undefined;

      const attrs = [
        `BANDWIDTH=${v.bw || 0}`,
        v.res ? `RESOLUTION=${v.res}` : null,
        // Add video codec + one audio codec from any audio variant (if available)
        v.codecs ? `CODECS="${v.codecs}${audioGroup ? ',' + (audioByLang[audioGroup]?.[0]?.codecs || 'mp4a.40.2') : ''}"` : null,
        audioGroup ? `AUDIO="${audioGroup}"` : null,
      ].filter(Boolean).join(',');

      master.push(`#EXT-X-STREAM-INF:${attrs}`);
      master.push(v.uri);
    }

    fs.writeFileSync(path.join(outDir, 'master.m3u8'), master.join('\n'), 'utf-8');
    console.log(`Wrote master.m3u8 to ${outDir}`);
  } catch (err) {
    console.error('Failed:', err.message);
    process.exit(1);
  }
})();
