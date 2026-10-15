<?php
// Returns the "Firearm details" panel HTML for the listing editor (GET ?id=). Login required.
require __DIR__ . '/_admin.php';
require_login();
require __DIR__ . '/_firearm_panel.php';
header('Content-Type: text/html; charset=utf-8');
echo firearm_panel_html((int)get_str('id', '0'));
