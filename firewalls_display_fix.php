                                <!-- IPv4 WAN Column -->
                                <td class="text-light">
                                    <?php if (!empty($firewall["wan_ip"]) && filter_var($firewall["wan_ip"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)): ?>
                                        <?php echo htmlspecialchars($firewall["wan_ip"]); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Awaiting Agent Data</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- IPv6 WAN Column -->
                                <td class="text-light">
                                    <?php if (!empty($firewall["ipv6_address"])): ?>
                                        <small><?php echo htmlspecialchars(substr($firewall["ipv6_address"], 0, 25)) . '...'; ?></small>
                                    <?php elseif (!empty($firewall["wan_ip"]) && filter_var($firewall["wan_ip"], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)): ?>
                                        <small><?php echo htmlspecialchars(substr($firewall["wan_ip"], 0, 25)) . '...'; ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- LAN IP Column -->
                                <td class="text-light">
                                    <?php if (!empty($firewall["lan_ip"])): ?>
                                        <?php echo htmlspecialchars($firewall["lan_ip"]); ?>
                                    <?php elseif (!empty($firewall["ip_address"])): ?>
                                        <?php echo htmlspecialchars($firewall["ip_address"]); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Awaiting Agent Data</span>
                                    <?php endif; ?>
                                </td>
