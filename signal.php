<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$signal_file = __DIR__ . '/.webrtc_signals.json';

// Initialize if not exists
if (!file_exists($signal_file)) {
    file_put_contents($signal_file, json_encode([
        'offer' => null, 
        'answer' => null, 
        'ice_candidates' => [],
        'broadcaster_ready' => false
    ]));
    chmod($signal_file, 0777);
}

function readSignals() {
    global $signal_file;
    $content = file_get_contents($signal_file);
    return json_decode($content, true);
}

function writeSignals($data) {
    global $signal_file;
    file_put_contents($signal_file, json_encode($data));
}

$action = $_GET['action'] ?? '';

if ($action === 'reset') {
    writeSignals(['offer' => null, 'answer' => null, 'ice_candidates' => [], 'broadcaster_ready' => false]);
    echo json_encode(['status' => 'reset']);
}
elseif ($action === 'set_offer') {
    $signals = readSignals();
    $signals['offer'] = $_POST['sdp'] ?? null;
    $signals['answer'] = null;
    $signals['ice_candidates'] = [];
    $signals['broadcaster_ready'] = true;
    writeSignals($signals);
    echo json_encode(['status' => 'ok', 'offer_set' => true]);
}
elseif ($action === 'get_offer') {
    $signals = readSignals();
    echo json_encode(['offer' => $signals['offer'], 'ready' => $signals['broadcaster_ready']]);
}
elseif ($action === 'set_answer') {
    $signals = readSignals();
    $signals['answer'] = $_POST['sdp'] ?? null;
    writeSignals($signals);
    echo json_encode(['status' => 'ok']);
}
elseif ($action === 'get_answer') {
    $signals = readSignals();
    echo json_encode(['answer' => $signals['answer']]);
}
elseif ($action === 'add_ice') {
    $signals = readSignals();
    $candidate = json_decode($_POST['candidate'], true);
    if ($candidate) {
        $signals['ice_candidates'][] = $candidate;
        writeSignals($signals);
    }
    echo json_encode(['status' => 'ok']);
}
elseif ($action === 'get_ice') {
    $signals = readSignals();
    $candidates = $signals['ice_candidates'];
    echo json_encode(['candidates' => $candidates]);
}
elseif ($action === 'clear_ice') {
    $signals = readSignals();
    $signals['ice_candidates'] = [];
    writeSignals($signals);
    echo json_encode(['status' => 'ok']);
}
?>