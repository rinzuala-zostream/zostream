<?php

return [
    // Where to store generated HLS
    'hls_root' => env('HLS_ROOT', public_path('hls')),

    // Node + script paths
    'mpd2hls_node'   => env('MPD2HLS_NODE', '/usr/bin/node'),
    'mpd2hls_script' => env('MPD2HLS_SCRIPT', base_path('scripts/mpd-to-hls.js')),
];
