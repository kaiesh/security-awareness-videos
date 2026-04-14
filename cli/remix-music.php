<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';

use SecurityDrama\Bootstrap;
use SecurityDrama\Video\VideoOrchestrator;

Bootstrap::init();

/**
 * Remix background music onto already-completed videos.
 *
 * Usage:
 *   php cli/remix-music.php                   # remix all composited=0 videos
 *   php cli/remix-music.php --video-id=42     # remix just videos.id = 42
 *   php cli/remix-music.php --all --force     # remix every completed video,
 *                                             #   including ones already composited
 *
 * Downloads the current mp4 from DO Spaces, picks a random active track for
 * the queue item's content_type, stream-copies the video with the music mixed
 * under the narrator audio, and re-uploads to the same storage path.
 */

$options = getopt('', ['video-id::', 'all', 'force', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Usage: php cli/remix-music.php [--video-id=N] [--all] [--force]

  --video-id=N   Remix a single video by videos.id.
  --all          Remix every completed video (default: only composited=0).
  --force        Include videos that are already composited=1.
  --help         Show this message.

Without any flag, the script remixes every video with composited=0.
HELP;
    echo "\n";
    exit(0);
}

$videoId = isset($options['video-id']) ? (int) $options['video-id'] : null;
$force   = isset($options['force']);

if ($videoId !== null && $videoId < 1) {
    fwrite(STDERR, "Invalid --video-id value.\n");
    exit(1);
}

$lockFile = fopen('/tmp/securitydrama_remix_music.lock', 'c');
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "Another remix process is already running.\n";
    exit(1);
}

$orchestrator = new VideoOrchestrator();
$remixed = $orchestrator->remixMusic($videoId, $force);

echo "Music remix complete. Videos remixed: {$remixed}\n";
