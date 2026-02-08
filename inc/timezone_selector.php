<?php
/**
 * Timezone Selector Component
 * Provides timezone selection for log and queue pages
 */

// Default timezone setting
$default_timezone = 'America/New_York'; // EST/EDT

// Get current timezone from session or use default
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_timezone = $_SESSION['display_timezone'] ?? $default_timezone;

// Handle timezone change
if (isset($_POST['change_timezone'])) {
    $new_timezone = $_POST['timezone'] ?? $default_timezone;
    if (in_array($new_timezone, timezone_identifiers_list())) {
        $_SESSION['display_timezone'] = $new_timezone;
        $current_timezone = $new_timezone;
    }
}

/**
 * Convert UTC timestamp to selected timezone
 */
function convertToDisplayTimezone($utc_timestamp, $format = 'Y-m-d H:i:s') {
    global $current_timezone;
    
    if (empty($utc_timestamp) || $utc_timestamp === '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    try {
        $utc = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
        $local_tz = new DateTimeZone($current_timezone);
        $utc->setTimezone($local_tz);
        
        // Add timezone abbreviation
        $formatted = $utc->format($format);
        $tz_abbr = $utc->format('T');
        
        return $formatted . ' ' . $tz_abbr;
    } catch (Exception $e) {
        return $utc_timestamp;
    }
}

/**
 * Get relative time string (e.g., "5 minutes ago")
 */
function getRelativeTime($utc_timestamp) {
    global $current_timezone;
    
    if (empty($utc_timestamp) || $utc_timestamp === '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    try {
        $utc = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
        $local_tz = new DateTimeZone($current_timezone);
        $utc->setTimezone($local_tz);
        
        $now = new DateTime('now', $local_tz);
        $diff = $now->diff($utc);
        
        if ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    } catch (Exception $e) {
        return 'Unknown';
    }
}

/**
 * Render timezone selector widget
 */
function renderTimezoneSelector() {
    global $current_timezone;
    
    $common_timezones = [
        'America/New_York' => 'Eastern Time (EST/EDT)',
        'America/Chicago' => 'Central Time (CST/CDT)',
        'America/Denver' => 'Mountain Time (MST/MDT)',
        'America/Los_Angeles' => 'Pacific Time (PST/PDT)',
        'UTC' => 'UTC (Coordinated Universal Time)',
        'Europe/London' => 'London (GMT/BST)',
        'Europe/Paris' => 'Paris (CET/CEST)',
        'Asia/Tokyo' => 'Tokyo (JST)',
        'Australia/Sydney' => 'Sydney (AEST/AEDT)'
    ];
    
    echo '<div class="timezone-selector d-inline-flex align-items-center ms-3">';
    echo '<form method="post" class="d-inline-flex align-items-center">';
    echo '<label for="timezone" class="form-label me-2 mb-0 small">Timezone:</label>';
    echo '<select name="timezone" id="timezone" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">';
    
    foreach ($common_timezones as $tz => $label) {
        $selected = ($tz === $current_timezone) ? 'selected' : '';
        echo "<option value=\"$tz\" $selected>$label</option>";
    }
    
    echo '</select>';
    echo '<input type="hidden" name="change_timezone" value="1">';
    echo '</form>';
    echo '</div>';
}

/**
 * Add JavaScript for automatic refresh when timezone changes
 */
function addTimezoneJS() {
    echo '<script>
        // Auto-refresh page when timezone changes for better UX
        document.addEventListener("DOMContentLoaded", function() {
            const tzSelect = document.getElementById("timezone");
            if (tzSelect) {
                tzSelect.addEventListener("change", function() {
                    // Add loading indicator
                    const option = this.options[this.selectedIndex];
                    option.text = "Loading...";
                    this.disabled = true;
                });
            }
        });
    </script>';
}
?>