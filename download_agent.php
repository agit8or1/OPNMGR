<?php
// Retired in 3.81.3. This served the pre-1.0 shell agent (v3.6.1), which
// disabled TLS verification, to anyone who asked. Current agents install from
// /downloads/plugins/ with a checksum (see Add Firewall in OPNManager).
http_response_code(410);
header('Content-Type: text/plain');
echo "This agent download has been retired. Use the install command on OPNManager's Add Firewall page.\n";
