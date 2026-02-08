$query = 'SELECT f.*, t.name as tag_name, t.color as tag_color, ft.tag_id, fa.agent_version as version, fa.status as agent_status, fa.last_checkin as agent_last_checkin, fa.wan_ip, fa.ipv6_address FROM firewalls f LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id LEFT JOIN firewall_tags ft ON f.id = ft.firewall_id LEFT JOIN tags t ON ft.tag_id = t.id WHERE 1=1';
$params = array();

if (!empty($search)) {
    $query .= ' AND (f.hostname LIKE ? OR f.ip_address LIKE ? OR f.customer_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if (!empty($tag_filter)) {
    $query .= ' AND ft.tag_id = ?';
    $params[] = $tag_filter;
}

$query .= ' ORDER BY f.' . $sort_by . ' ' . $sort_order;
$query .= ' LIMIT ' . $per_page . ' OFFSET ' . $offset;
