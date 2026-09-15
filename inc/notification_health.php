<?php
/**
 * Is anyone actually being told?
 *
 * The alerting pipeline worked correctly and nobody knew it was useless. It
 * detected conditions, raised incidents, and recorded each notification with
 * status 'failed' - 911 of them on the maintainer's installation, not one
 * 'sent', going back to the first row in the table. The SMTP credential had
 * been rejected by the provider the whole time. Nothing surfaced that: no
 * banner, no dashboard tile, no health signal. The only trace was 114,355 lines
 * in a log nobody reads.
 *
 * That is worse than having no alerting, because the operator believes they are
 * covered. A monitoring system that cannot deliver has to say so.
 *
 * The one rule here: this must never be reported *by* the channel it is
 * reporting on. A broken email path cannot email you about being broken, so
 * this is surfaced in the interface instead.
 *
 * @since 3.33.0
 */

if (!function_exists('notification_health')) {
    /**
     * Delivery state of each notification channel.
     *
     * Returns one entry per channel that has ever been used, with the number of
     * consecutive failures since its last success. `ok` is false once a channel
     * has failed every attempt for long enough that it cannot be a transient
     * outage.
     */
    function notification_health(int $consecutiveFailureLimit = 3): array
    {
        $channels = [];

        try {
            $rows = db()->query(
                "SELECT notification_method, status, error_message, sent_at
                   FROM alert_history
                  WHERE notification_method IS NOT NULL AND notification_method <> ''
                  ORDER BY sent_at DESC, id DESC
                  LIMIT 500"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read notification history: ' . $e->getMessage());
            return [];
        }

        return notification_health_from_rows($rows, $consecutiveFailureLimit, true);
    }
}

if (!function_exists('notification_health_from_rows')) {
    /**
     * The aggregation, separated from the query so it can be tested directly.
     *
     * $rows must be newest first. $mayCountBeyondWindow asks the database for a
     * true total when a channel is still failing at the end of the window; a
     * test passes false and gets exactly what its fixture describes.
     */
    function notification_health_from_rows(
        array $rows,
        int $consecutiveFailureLimit = 3,
        bool $mayCountBeyondWindow = false
    ): array {
        $channels = [];

        foreach ($rows as $row) {
            $channel = (string) $row['notification_method'];

            if (!isset($channels[$channel])) {
                $channels[$channel] = [
                    'channel'              => $channel,
                    'consecutive_failures' => 0,
                    'last_success'         => null,
                    'last_failure'         => null,
                    'last_error'           => null,
                    'counting'             => true,
                ];
            }

            $c = &$channels[$channel];
            $delivered = in_array($row['status'], ['sent', 'partial'], true);

            if ($delivered) {
                // Rows are newest first, so the first delivery we meet ends the
                // run of consecutive failures.
                if ($c['last_success'] === null) {
                    $c['last_success'] = $row['sent_at'];
                }
                $c['counting'] = false;
            } else {
                if ($c['counting']) {
                    $c['consecutive_failures']++;
                }
                if ($c['last_failure'] === null) {
                    $c['last_failure'] = $row['sent_at'];
                    $c['last_error']   = $row['error_message'];
                }
            }
            unset($c);
        }

        foreach ($channels as &$c) {
            // The scan window is bounded, so a channel still counting failures
            // at the end of it has more than we have seen. Ask for the real
            // figure rather than reporting the window size as if it were the
            // total - "500 failures" when it is actually 911 understates a
            // problem that is already being understated by going unnoticed.
            if ($mayCountBeyondWindow && $c['counting'] && $c['last_success'] === null) {
                try {
                    $stmt = db()->prepare(
                        'SELECT COUNT(*) FROM alert_history WHERE notification_method = ?'
                    );
                    $stmt->execute([$c['channel']]);
                    $c['consecutive_failures'] = max($c['consecutive_failures'], (int) $stmt->fetchColumn());
                } catch (Throwable $e) {
                    // Keep the windowed count; it is a floor, not a lie.
                    error_log('OPNMGR: could not total notification failures: ' . $e->getMessage());
                }
            }

            unset($c['counting']);
            $c['ok'] = $c['consecutive_failures'] < $consecutiveFailureLimit;
            // A channel that has never once delivered is a configuration
            // failure rather than an outage, and is worth saying differently.
            $c['never_delivered'] = $c['last_success'] === null && $c['consecutive_failures'] > 0;
        }
        unset($c);

        return array_values($channels);
    }
}

if (!function_exists('notification_health_problems')) {
    /** Only the channels that are failing. Empty array means delivery is working. */
    function notification_health_problems(int $consecutiveFailureLimit = 3): array
    {
        return array_values(array_filter(
            notification_health($consecutiveFailureLimit),
            static fn(array $c): bool => !$c['ok']
        ));
    }
}

if (!function_exists('notification_health_banner')) {
    /**
     * The warning shown at the top of the interface, or '' when delivery works.
     *
     * Deliberately not dismissible and not rate limited. The failure it reports
     * persists until someone fixes a credential, and a banner an operator can
     * wave away is how 911 undelivered alerts go unnoticed.
     */
    function notification_health_banner(): string
    {
        $problems = notification_health_problems();
        if (!$problems) {
            return '';
        }

        $parts = [];
        foreach ($problems as $c) {
            $label = htmlspecialchars(ucfirst($c['channel']));
            if ($c['never_delivered']) {
                $parts[] = "<strong>{$label} has never delivered a notification</strong> ("
                         . (int) $c['consecutive_failures'] . ' recorded attempts, all failed)';
            } else {
                $parts[] = "<strong>{$label}</strong> has failed "
                         . (int) $c['consecutive_failures'] . ' consecutive attempts since '
                         . htmlspecialchars((string) $c['last_success']);
            }
        }

        $detail = '';
        $error = $problems[0]['last_error'] ?? '';
        if (is_string($error) && $error !== '') {
            $detail = '<div class="small mt-1 text-break">Last error: <code>'
                    . htmlspecialchars(substr($error, 0, 300)) . '</code></div>';
        }

        return '<div class="alert alert-danger mb-3" role="alert">'
             . '<i class="fas fa-bell-slash me-2"></i>'
             . 'Alerts are being raised but not delivered. ' . implode('. ', $parts) . '.'
             . $detail
             . '<div class="small mt-2">Check the notification settings on '
             . '<a href="/alerts.php" class="alert-link">the Alerts page</a>. '
             . 'This warning cannot be emailed to you, for obvious reasons.</div>'
             . '</div>';
    }
}
