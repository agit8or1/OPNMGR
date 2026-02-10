<?php
/**
 * Format speed value for display
 * Shows Gbps for speeds >= 1000 Mbps, otherwise Mbps
 *
 * @param float $mbps Speed in Mbps
 * @param int $decimals Number of decimal places (default 1 for Gbps, 0 for Mbps)
 * @return string Formatted speed string like "12.3 Gbps" or "950 Mbps"
 */
function format_speed($mbps, $decimals = null) {
    $mbps = (float)$mbps;

    if ($mbps >= 1000) {
        $gbps = $mbps / 1000;
        $decimals = $decimals ?? 1; // Default to 1 decimal for Gbps
        return number_format($gbps, $decimals) . ' Gbps';
    } else {
        $decimals = $decimals ?? 0; // Default to 0 decimals for Mbps
        return number_format($mbps, $decimals) . ' Mbps';
    }
}

/**
 * Format speed value as array with value and unit
 * Useful for APIs that need structured data
 *
 * @param float $mbps Speed in Mbps
 * @return array ['value' => float, 'unit' => string, 'formatted' => string]
 */
function format_speed_array($mbps) {
    $mbps = (float)$mbps;

    if ($mbps >= 1000) {
        $gbps = $mbps / 1000;
        return [
            'value' => round($gbps, 2),
            'unit' => 'Gbps',
            'formatted' => format_speed($mbps)
        ];
    } else {
        return [
            'value' => round($mbps, 0),
            'unit' => 'Mbps',
            'formatted' => format_speed($mbps)
        ];
    }
}
?>
