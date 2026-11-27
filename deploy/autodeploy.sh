#!/bin/bash
#
# Auto-deploy for pardusmapper.com (HostGator / cPanel).
#
# Does what the "Update from Remote" and "Deploy HEAD Commit" buttons in cPanel -> Git Version
# Control do, but only when GitHub's main branch has a new commit, and only if every changed PHP
# file passes a syntax check (so a typo can't take the site down).
#
# Run it from cPanel -> Cron Jobs every 5 minutes:
#   */5 * * * * /bin/bash /home2/ifcpaute/repositories/pardusmapper/deploy/autodeploy.sh
#
# It is quiet when there is nothing new. Each deploy (or skipped deploy) is written to
#   ~/logs/pardusmapper-autodeploy.log
#
# This file updates itself when it pulls, so all the work is inside main(): bash reads the whole
# function before running it, so the update can't change the script while it is running.

main() {
    local REPO=${PM_REPO:-/home2/ifcpaute/repositories/pardusmapper}
    local BRANCH=main
    local LOG="$HOME/logs/pardusmapper-autodeploy.log"
    local LOCK="$HOME/.pardusmapper-autodeploy.lock"

    mkdir -p "$(dirname "$LOG")"
    log() { echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') $*" >> "$LOG"; }

    # never run two at once (a slow pull/deploy overlapping the next cron run)
    exec 9> "$LOCK"
    flock -n 9 || return 0

    cd "$REPO" || { log "ERROR: repository not found at $REPO"; return 1; }

    git fetch --quiet origin "$BRANCH" || { log "ERROR: git fetch failed"; return 1; }
    local OLD NEW
    OLD=$(git rev-parse HEAD)
    NEW=$(git rev-parse "origin/$BRANCH")
    [ "$OLD" = "$NEW" ] && return 0   # nothing new

    # Syntax-check the PHP files that changed, using the new versions, before anything goes live.
    # Use PHP 8.3 (what the site runs) if cPanel's copy is there, otherwise whatever php is installed.
    local PHP=/opt/cpanel/ea-php83/root/usr/bin/php
    [ -x "$PHP" ] || PHP=$(command -v php)
    local BAD="" f tmp
    if [ -n "$PHP" ]; then
        tmp=$(mktemp)
        while IFS= read -r f; do
            git show "$NEW:$f" > "$tmp" 2>/dev/null || continue
            "$PHP" -l "$tmp" > /dev/null 2>&1 || BAD="$BAD $f"
        done < <(git diff --name-only --diff-filter=AM "$OLD" "$NEW" -- '*.php')
        rm -f "$tmp"
    else
        log "WARNING: php not found, deploying without a syntax check"
    fi
    if [ -n "$BAD" ]; then
        # Log it once per commit, then keep quiet until main changes again.
        if [ "$(cat "$LOCK.skipped" 2>/dev/null)" != "$NEW" ]; then
            log "SKIPPED ${NEW:0:7}: PHP syntax error in:$BAD (site left on ${OLD:0:7})"
            echo "$NEW" > "$LOCK.skipped"
        fi
        return 1
    fi

    # "Update from Remote", then "Deploy HEAD Commit" (runs .cpanel.yml), the same way cPanel does.
    # uapi exits 0 even when the call fails, so check the "status: 1" in its reply.
    local UAPI OUT
    UAPI=$(command -v uapi || echo /usr/local/cpanel/bin/uapi)
    OUT=$("$UAPI" VersionControl update repository_root="$REPO" branch="$BRANCH" 2>&1)
    echo "$OUT" | grep -q 'status: 1' || { log "ERROR: update from remote failed: $(echo "$OUT" | grep -A3 'errors:' | tr '\n' ' ')"; return 1; }
    [ "$(git rev-parse HEAD)" = "$NEW" ] || { log "ERROR: update did not reach ${NEW:0:7}"; return 1; }
    OUT=$("$UAPI" VersionControlDeployment create repository_root="$REPO" 2>&1)
    echo "$OUT" | grep -q 'status: 1' || { log "ERROR: deploy of ${NEW:0:7} failed: $(echo "$OUT" | grep -A3 'errors:' | tr '\n' ' ')"; return 1; }

    log "DEPLOYED ${OLD:0:7} -> ${NEW:0:7}: $(git log -1 --format=%s "$NEW")"
}

main "$@"
exit $?
