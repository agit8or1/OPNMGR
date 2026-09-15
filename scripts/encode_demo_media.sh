#!/bin/sh
# Encode the recorded walkthrough into the shapes that get published.
#
# Input is whatever scripts/record_demo_walkthrough.js produced. Everything is
# written next to it, outside the repository: the MP4s are release assets, not
# tracked files, because a binary that regenerates from a script does not need
# to sit in git history forever. Only the poster frame is committed.
#
#   OPNMGR_DEMO=1 php scripts/demo_fixture.php
#   DEMO_PASS='...' node scripts/record_demo_walkthrough.js
#   sh scripts/encode_demo_media.sh
#
# Requires ffmpeg.
#
# @since 3.25.0

set -e

DIR="${DEMO_VIDEO_DIR:-/tmp/opnmgr-demo-media}"
RAW="$DIR/walkthrough.raw.webm"
REPO_POSTER="${REPO_POSTER:-docs/images/github/walkthrough-poster.png}"

[ -f "$RAW" ] || { echo "No recording at $RAW"; exit 1; }
command -v ffmpeg >/dev/null || { echo "ffmpeg is required"; exit 1; }

echo "Source: $RAW"

# --- Main walkthrough: 1080p30, H.264 High, yuv420p, faststart --------------
# yuv420p and the baseline-compatible profile matter: they are what makes the
# file play in browsers and on phones rather than only in desktop players.
echo "Encoding walkthrough.mp4 (duration is reported once encoded)"
ffmpeg -v error -y -i "$RAW" \
  -r 30 -c:v libx264 -profile:v high -level 4.0 -pix_fmt yuv420p \
  -crf 22 -preset slow -movflags +faststart -an \
  "$DIR/opnmanager-walkthrough-1080p.mp4"

# --- Highlight clip ---------------------------------------------------------
# Cut from the encoded MP4 rather than the raw WebM, and stitched with the
# concat demuxer rather than filter_complex: trimming a long source inside a
# filter graph buffers the whole thing and gets the process OOM-killed on a
# small host.
#
# Segments: the opening title, and the configuration diff - the two things that
# explain the product fastest.
echo "Encoding highlight clip"
MAIN="$DIR/opnmanager-walkthrough-1080p.mp4"
SEGDIR="$DIR/.segments"
rm -rf "$SEGDIR"; mkdir -p "$SEGDIR"

extract() {  # start duration out
  ffmpeg -v error -y -ss "$1" -i "$MAIN" -t "$2" \
    -c:v libx264 -profile:v high -level 4.0 -pix_fmt yuv420p \
    -crf 23 -preset medium -an "$3"
}
extract 1   14 "$SEGDIR/a.mp4"
extract 104 26 "$SEGDIR/b.mp4"

printf "file '%s'\nfile '%s'\n" "$SEGDIR/a.mp4" "$SEGDIR/b.mp4" > "$SEGDIR/list.txt"
ffmpeg -v error -y -f concat -safe 0 -i "$SEGDIR/list.txt" \
  -c copy -movflags +faststart "$DIR/opnmanager-highlight-1080p.mp4"
rm -rf "$SEGDIR"

ffprobe -v error -show_entries format=duration -of default=nk=1:nw=1 \
  "$DIR/opnmanager-walkthrough-1080p.mp4" | awk '{printf "  walkthrough %.0fs\n", $1}'

# --- Poster frame (committed) ----------------------------------------------
# Taken from the title card, so the thumbnail says what the product is.
echo "Extracting poster -> $REPO_POSTER"
ffmpeg -v error -y -ss 6 -i "$RAW" -frames:v 1 "$DIR/poster.raw.png"
python3 - "$DIR/poster.raw.png" "$REPO_POSTER" <<'PY'
import sys
from PIL import Image, ImageDraw
src, dst = sys.argv[1], sys.argv[2]
im = Image.open(src).convert("RGB")
d = ImageDraw.Draw(im, "RGBA")
# A play badge, so the still reads as a video rather than another screenshot.
w, h = im.size
r = int(min(w, h) * 0.085)
cx, cy = w // 2, int(h * 0.5)
d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=(255, 255, 255, 234))
t = int(r * 0.52)
d.polygon([(cx - t * 0.45, cy - t), (cx - t * 0.45, cy + t), (cx + t * 0.85, cy)],
          fill=(14, 17, 23, 255))
im.quantize(colors=256, dither=Image.Dither.NONE).save(dst, optimize=True)
print(f"  poster {im.size[0]}x{im.size[1]}")
PY

echo
ls -lh "$DIR"/*.mp4 "$REPO_POSTER" | awk '{print "  " $5 "  " $9}'
echo
echo "The MP4s are release assets. Upload with:"
echo "  gh release upload <tag> $DIR/opnmanager-walkthrough-1080p.mp4 \\"
echo "    $DIR/opnmanager-highlight-1080p.mp4 docs/walkthrough.vtt"
