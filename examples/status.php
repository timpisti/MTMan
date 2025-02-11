<?php
// status.php
if (isset($_GET['file']) && file_exists($_GET['file'])) {
    header('Content-Type: application/json');
    echo file_get_contents($_GET['file']);
    exit;
}
echo json_encode(['error' => 'Status file not found']);