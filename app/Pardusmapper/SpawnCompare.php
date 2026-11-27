<?php
declare(strict_types=1);

namespace Pardusmapper;

use Pardusmapper\Core\Settings;

/**
 * Compare where and when a rare NPC turns up in each universe.
 *
 * Sightings are grouped into appearances: an NPC seen in a sector, and seen there again less than
 * APPEARANCE_GAP later, is one appearance (a spawn, or a visit by a moving NPC). The appearances of
 * each pair of universes are then compared: how many have an appearance in the same sector in the
 * other universe within a time window, and how many in that sector at any time. If the first is
 * close to the second, the universes follow each other in time; if only the second is high, they
 * use the same sectors but not in step.
 */
class SpawnCompare
{
    /** Seen again in the same sector within this many seconds: the same appearance. */
    public const APPEARANCE_GAP = 172800;

    /** Row grouping for the side-by-side list, and the matching window (days) that goes with it. */
    public const PERIODS = ['day' => 1, 'week' => 7, 'month' => 30];

    /** Fewer appearances than this in either universe: too little to judge. */
    public const MIN_APPEARANCES = 5;

    /**
     * @param array $rows sightings from NPC::mark_sightings(), oldest first
     * @return array list of appearances, oldest first:
     *               ['uni','sector','x','y','first','last' (epoch),'sightings','ids' => [nid => true]]
     */
    public static function appearances(array $rows): array
    {
        $list = [];
        $open = []; // "uni|sector" => index in $list of its latest appearance

        foreach ($rows as $r) {
            $ts = (int) ($r->ts ?? strtotime($r->spotted . ' UTC'));
            $key = $r->uni . '|' . $r->sector;
            $i = $open[$key] ?? null;

            if (!is_null($i) && $ts - $list[$i]['last'] < self::APPEARANCE_GAP) {
                $list[$i]['last'] = $ts;
            } else {
                $list[] = ['uni' => $r->uni, 'sector' => (string) $r->sector, 'x' => (int) $r->x, 'y' => (int) $r->y,
                           'first' => $ts, 'last' => $ts, 'sightings' => 0, 'ids' => []];
                $i = $open[$key] = array_key_last($list);
            }
            if (!isset($r->counted) || $r->counted) {
                $list[$i]['sightings']++;
            }
            if (!is_null($r->nid)) {
                $list[$i]['ids'][(int) $r->nid] = true;
            }
        }

        return $list;
    }

    /**
     * Group appearances into rows by when they started: period start => uni => [appearances].
     *
     * @param array $apps from appearances()
     * @param string $period day | week | month
     * @param bool $newestFirst
     * @return array
     */
    public static function timeline(array $apps, string $period, bool $newestFirst = true): array
    {
        $rows = [];
        foreach ($apps as $a) {
            $key = self::periodStart($a['first'], $period);
            $rows[$key][$a['uni']][] = $a;
        }
        $newestFirst ? krsort($rows) : ksort($rows);

        return $rows;
    }

    /** Start of the day / ISO week (Monday) / month containing $ts, as "YYYY-MM-DD" (UTC). */
    public static function periodStart(int $ts, string $period): string
    {
        return match ($period) {
            'month' => gmdate('Y-m-01', $ts),
            'week' => gmdate('Y-m-d', $ts - ((int) gmdate('N', $ts) - 1) * 86400),
            default => gmdate('Y-m-d', $ts),
        };
    }

    /**
     * Score each pair of universes.
     *
     * Only the time both universes have sightings in (plus the window) is compared, so a universe
     * with a short history isn't marked down for years it has no data for.
     *
     * @param array $apps from appearances()
     * @param int $windowDays
     * @param array $clusterOf sector name => cluster code (UniverseMap::layout()['sectors'])
     * @return array list of ['a','b','from','to','n','near','ever','nearCluster','lag' (hours|null),'verdict']
     */
    public static function compare(array $apps, int $windowDays, array $clusterOf = []): array
    {
        $window = $windowDays * 86400;
        $byUni = [];
        foreach ($apps as $a) {
            $byUni[$a['uni']][] = $a;
        }

        $pairs = [];
        $unis = Settings::UNIVERSE;
        for ($i = 0; $i < count($unis); $i++) {
            for ($j = $i + 1; $j < count($unis); $j++) {
                $pairs[] = self::comparePair($unis[$i], $unis[$j], $byUni[$unis[$i]] ?? [], $byUni[$unis[$j]] ?? [], $window, $clusterOf);
            }
        }

        return $pairs;
    }

    private static function comparePair(string $ua, string $ub, array $a, array $b, int $window, array $clusterOf): array
    {
        $result = ['a' => $ua, 'b' => $ub, 'from' => null, 'to' => null, 'n' => 0,
                   'near' => 0.0, 'ever' => 0.0, 'nearCluster' => 0.0, 'lag' => null, 'verdict' => ''];

        if (!$a || !$b) {
            $result['verdict'] = 'Not seen in both universes yet.';
            return $result;
        }

        // the stretch of time both universes have sightings for
        $from = max(min(array_column($a, 'first')), min(array_column($b, 'first'))) - $window;
        $to = min(max(array_column($a, 'first')), max(array_column($b, 'first'))) + $window;
        $result['from'] = $from + $window;
        $result['to'] = $to - $window;
        $inRange = static fn (array $list) => array_values(array_filter($list, static fn ($x) => $x['first'] >= $from && $x['first'] <= $to));
        $a = $inRange($a);
        $b = $inRange($b);

        if (count($a) < self::MIN_APPEARANCES || count($b) < self::MIN_APPEARANCES || $result['from'] > $result['to']) {
            $result['n'] = count($a) + count($b);
            $result['verdict'] = 'Not enough sightings in both universes over the same time to judge.';
            return $result;
        }

        $near = $ever = $nearCluster = 0;
        $lags = []; // hours, $ub minus $ua, for same-sector matches
        foreach ([[$a, $b, 1], [$b, $a, -1]] as [$from_, $to_, $sign]) {
            foreach ($from_ as $x) {
                $best = null;
                $sameEver = false;
                $clusterNear = false;
                foreach ($to_ as $y) {
                    $dt = $y['first'] - $x['first'];
                    $sameSector = $y['sector'] === $x['sector'];
                    if ($sameSector) {
                        $sameEver = true;
                        if (abs($dt) <= $window && (is_null($best) || abs($dt) < abs($best))) {
                            $best = $dt;
                        }
                    }
                    if (!$clusterNear && abs($dt) <= $window && isset($clusterOf[$x['sector']])
                        && ($clusterOf[$y['sector']] ?? null) === $clusterOf[$x['sector']]) {
                        $clusterNear = true;
                    }
                }
                $ever += $sameEver ? 1 : 0;
                $nearCluster += $clusterNear ? 1 : 0;
                if (!is_null($best)) {
                    $near++;
                    if ($sign === 1) {
                        $lags[] = $best / 3600;
                    }
                }
            }
        }

        $n = count($a) + count($b);
        $result['n'] = $n;
        $result['near'] = $near / $n;
        $result['ever'] = $ever / $n;
        $result['nearCluster'] = $nearCluster / $n;
        if ($lags) {
            sort($lags);
            $result['lag'] = $lags[intdiv(count($lags), 2)];
        }
        $result['verdict'] = self::verdict($result, $ua, $ub);

        return $result;
    }

    private static function verdict(array $r, string $ua, string $ub): string
    {
        if ($r['ever'] < 0.25) {
            return 'Mostly different sectors.';
        }
        $inStep = $r['near'] / $r['ever'];
        if ($r['near'] >= 0.4 && $inStep >= 0.6) {
            $text = 'Same sectors at about the same time';
            if (!is_null($r['lag']) && abs($r['lag']) >= 12) {
                $text .= sprintf(', %s usually about %s %s %s', $ub, self::duration(abs($r['lag'])), $r['lag'] > 0 ? 'after' : 'before', $ua);
            }
            return $text . '.';
        }
        if ($inStep >= 0.4) {
            return 'Often the same sectors around the same time, but not reliably.';
        }

        return 'Same sectors, but not at the same time.';
    }

    /** "5 hours", "2 days" */
    public static function duration(float $hours): string
    {
        if ($hours < 36) {
            $n = (int) round($hours);
            return $n . ' hour' . ($n === 1 ? '' : 's');
        }
        $n = (int) round($hours / 24);
        return $n . ' day' . ($n === 1 ? '' : 's');
    }
}
