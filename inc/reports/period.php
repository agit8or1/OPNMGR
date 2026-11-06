<?php
/**
 * Reporting periods.
 *
 * A period is always computed in the scope's own timezone with
 * DateTimeImmutable and then frozen as absolute Unix seconds [start, end).
 * Hours are never assumed: a local day across a DST change is 23 or 25 hours
 * and a weekly/monthly period inherits that, so "hours in period" is always
 * (end - start) / 3600 and not days * 24.
 *
 * @since 3.79.0
 */

if (!function_exists('report_tz')) {
    /** A DateTimeZone, falling back to UTC (and saying so) for an unknown name. */
    function report_tz(?string $name): DateTimeZone {
        try {
            return new DateTimeZone(($name !== null && $name !== '') ? $name : 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }
}

if (!function_exists('report_period_resolve')) {
    /**
     * Resolve a period specification to absolute bounds.
     *
     * $spec['preset']: last_week (previous Monday..Sunday), last_month
     * (previous calendar month), last_7_days / last_30_days (rolling, ending at
     * the start of the current local hour... midnight today), or 'custom' with
     * 'from' and 'to' as Y-m-d (inclusive local dates).
     *
     * @return array{start:int,end:int,timezone:string,label:string,hours:float,days:array}
     * @throws InvalidArgumentException on a bad specification
     */
    function report_period_resolve(array $spec, string $timezone, ?DateTimeImmutable $now = null): array {
        $tz  = report_tz($timezone);
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
        $today = $now->setTime(0, 0, 0);
        $preset = (string)($spec['preset'] ?? 'last_week');

        switch ($preset) {
            case 'last_week':
                // ISO week: Monday start. "N" is 1 (Mon) .. 7 (Sun).
                $thisMonday = $today->modify('-' . ((int)$today->format('N') - 1) . ' days');
                $start = $thisMonday->modify('-7 days');
                $end   = $thisMonday;
                break;
            case 'last_month':
                $first = $today->setDate((int)$today->format('Y'), (int)$today->format('n'), 1);
                $start = $first->modify('-1 month');
                $end   = $first;
                break;
            case 'last_7_days':
                $start = $today->modify('-7 days');
                $end   = $today;
                break;
            case 'last_30_days':
                $start = $today->modify('-30 days');
                $end   = $today;
                break;
            case 'custom':
                $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($spec['from'] ?? ''), $tz);
                $to   = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($spec['to'] ?? ''), $tz);
                if (!$from || !$to || $from->format('Y-m-d') !== ($spec['from'] ?? '') || $to->format('Y-m-d') !== ($spec['to'] ?? '')) {
                    throw new InvalidArgumentException('Custom period needs valid from/to dates (YYYY-MM-DD)');
                }
                $start = $from;
                $end   = $to->modify('+1 day');   // "to" is inclusive
                if ($end <= $start) {
                    throw new InvalidArgumentException('Period end must not be before its start');
                }
                if (($end->getTimestamp() - $start->getTimestamp()) > 400 * 86400) {
                    throw new InvalidArgumentException('Period is limited to 400 days');
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown period preset');
        }

        $s = $start->getTimestamp();
        $e = $end->getTimestamp();
        return [
            'start'    => $s,
            'end'      => $e,
            'timezone' => $tz->getName(),
            'label'    => $start->format('Y-m-d H:i') . ' to ' . $end->format('Y-m-d H:i') . ' ' . $tz->getName(),
            'hours'    => ($e - $s) / 3600.0,
            'days'     => report_period_days($s, $e, $tz->getName()),
        ];
    }
}

if (!function_exists('report_period_days')) {
    /**
     * Local calendar days inside [start,end), each with its true length.
     * @return array<int,array{date:string,start:int,end:int,hours:float}>
     */
    function report_period_days(int $start, int $end, string $timezone): array {
        $tz = report_tz($timezone);
        $d = (new DateTimeImmutable('@' . $start))->setTimezone($tz);
        $out = [];
        while ($d->getTimestamp() < $end && count($out) < 800) {
            $next = $d->modify('+1 day')->setTime(0, 0, 0);
            if ($next->getTimestamp() <= $d->getTimestamp()) {
                break;
            }
            $dEnd = min($next->getTimestamp(), $end);
            $out[] = ['date' => $d->format('Y-m-d'), 'start' => $d->getTimestamp(), 'end' => $dEnd,
                      'hours' => ($dEnd - $d->getTimestamp()) / 3600.0];
            $d = $next;
        }
        return $out;
    }
}

if (!function_exists('report_next_run')) {
    /**
     * Next local run time strictly after $after for a schedule, as Unix seconds.
     * Weekly: run_day 1..7 (Mon..Sun) at run_hour. Monthly: run_day 1..28.
     * Built from local wall-clock fields, so a run at 06:00 stays at 06:00
     * across DST changes. A wall-clock hour that does not exist (spring
     * forward) resolves to the next valid instant, never to a skipped run.
     */
    function report_next_run(string $cadence, int $runDay, int $runHour, string $timezone, int $after): int {
        $tz = report_tz($timezone);
        $a  = (new DateTimeImmutable('@' . $after))->setTimezone($tz);
        $runHour = max(0, min(23, $runHour));
        if ($cadence === 'weekly') {
            $runDay = max(1, min(7, $runDay));
            $cand = $a->setTime($runHour, 0, 0)->modify('+' . (($runDay - (int)$a->format('N') + 7) % 7) . ' days');
            $cand = $cand->setTime($runHour, 0, 0);
            if ($cand->getTimestamp() <= $after) {
                $cand = $cand->modify('+7 days')->setTime($runHour, 0, 0);
            }
            return $cand->getTimestamp();
        }
        $runDay = max(1, min(28, $runDay));
        $cand = $a->setDate((int)$a->format('Y'), (int)$a->format('n'), $runDay)->setTime($runHour, 0, 0);
        if ($cand->getTimestamp() <= $after) {
            $cand = $a->setDate((int)$a->format('Y'), (int)$a->format('n'), 1)->modify('+1 month')
                      ->setDate((int)$a->modify('first day of next month')->format('Y'),
                                (int)$a->modify('first day of next month')->format('n'), $runDay)
                      ->setTime($runHour, 0, 0);
        }
        return $cand->getTimestamp();
    }
}
