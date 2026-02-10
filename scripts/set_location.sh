#!/bin/bash
# Set manual location for firewall or server
# Usage: ./set_location.sh <firewall_id> <latitude> <longitude>

if [ "$#" -ne 3 ]; then
    echo "Usage: $0 <firewall_id> <latitude> <longitude>"
    echo ""
    echo "Examples:"
    echo "  $0 21 39.0997 -94.5786    # Set firewall 21 to Kansas City, MO"
    echo "  $0 3 40.7128 -74.0060     # Set firewall 3 to New York, NY"
    echo ""
    echo "Common US Cities:"
    echo "  Los Angeles:    34.0522, -118.2437"
    echo "  New York:       40.7128, -74.0060"
    echo "  Chicago:        41.8781, -87.6298"
    echo "  Houston:        29.7604, -95.3698"
    echo "  Phoenix:        33.4484, -112.0740"
    echo "  Philadelphia:   39.9526, -75.1652"
    echo "  San Antonio:    29.4241, -98.4936"
    echo "  San Diego:      32.7157, -117.1611"
    echo "  Dallas:         32.7767, -96.7970"
    echo "  San Jose:       37.3382, -121.8863"
    echo "  Kansas City:    39.0997, -94.5786"
    exit 1
fi

FIREWALL_ID=$1
LATITUDE=$2
LONGITUDE=$3

mysql opnsense_fw -e "UPDATE firewalls SET latitude = $LATITUDE, longitude = $LONGITUDE WHERE id = $FIREWALL_ID"

if [ $? -eq 0 ]; then
    echo "✓ Location updated for firewall $FIREWALL_ID"
    echo "  Coordinates: $LATITUDE, $LONGITUDE"

    # Show the firewall info
    mysql opnsense_fw -e "SELECT id, hostname, customer_name, latitude, longitude FROM firewalls WHERE id = $FIREWALL_ID"
else
    echo "✗ Failed to update location"
    exit 1
fi
