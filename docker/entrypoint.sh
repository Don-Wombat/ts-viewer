#!/bin/sh
set -e

# TS_CACHE_DIR can be overlaid by a volume or bind mount - a mount completely
# overrides the ownership set in the image. So make sure here, at container
# start (not just once at image build time), that the directory exists and
# is owned by www-data, regardless of what's mounted there.
#
# "-R" matters for a persistent volume specifically: files already inside it
# from a previous container generation keep whatever owner THEY were created
# under, a plain non-recursive chown on the directory itself does nothing
# for them. This bit already once - online_time.json/.lock were created back
# when the online-time tracker still ran as root (before it was switched to
# www-data), and stayed root-owned across every redeploy since; www-data
# could only ever read them (644, not the owner), so every single poll
# failed at fopen() before even reaching the TS server, silently freezing
# the leaderboard - deploying a code fix elsewhere never touched these
# already-existing files, since nothing had ever chowned them.
CACHE_DIR="${TS_CACHE_DIR:-/var/cache/ts-viewer}"
mkdir -p "$CACHE_DIR"
chown -R www-data:www-data "$CACHE_DIR"
chmod 700 "$CACHE_DIR"

# Depending on the host's container runtime, /etc/resolv.conf (and
# sometimes /etc/hosts) can end up owned root:root with no "other" read
# permission - harmless for root, but it silently breaks DNS resolution for
# a www-data process (gethostbyname() just returns the hostname
# unresolved, no error, not an exception - the tracker below would fail
# every single poll with no obvious cause). Same "fix permissions at start,
# don't rely on what the runtime handed us" approach as the cache dir
# above, so the tracker can run as www-data like everything else in the
# container instead of needing root for what's ultimately a workaround for
# an unrelated file permission.
chmod a+r /etc/resolv.conf /etc/hosts 2>/dev/null || true

# Online-time leaderboard: a background poller independent of website
# traffic (unlike the rest of the app's data, which only refreshes when a
# visitor's request finds an expired cache). Off unless TS_TRACK_ONLINE_TIME
# is set, since it opens its own periodic ServerQuery connection. Runs as
# www-data, not root - it only ever reads ServerQuery data and writes to
# the already-www-data-owned cache dir above, so it doesn't need more.
# "timeout 30" around each poll: the SSH transport bounds its OWN read to 8s
# (see ts_transport_ssh.php) and its connect to TS_CONNECT_TIMEOUT (default
# 5s), but that only covers the ssh subprocess's own pipe - a proc_open()
# child that for whatever reason never closes its stdout (a wedged ssh
# process, a network condition the 8s read-timeout doesn't catch, ...) can
# still leave stream_get_contents() blocked forever. Without an outer bound,
# one such hang wedges this whole "while true" loop permanently: the process
# stays alive (looks fine in `ps`) but never reaches `sleep`/the next
# iteration again, silently freezing the leaderboard at whatever totals it
# last had - exactly what happened once already. "|| true" so a killed/timed-
# out run (or any other non-zero exit) can't stop the loop itself.
if [ -n "${TS_TRACK_ONLINE_TIME:-}" ]; then
  INTERVAL="${TS_TRACK_INTERVAL:-60}"
  echo "Online-time tracker: enabled, polling every ${INTERVAL}s"
  su -s /bin/sh -c "
    while true; do
      timeout 30 php /var/www/cli/track_online_time.php || true
      sleep '$INTERVAL'
    done
  " www-data &
fi

exec docker-php-entrypoint "$@"
