<?php

// =========================================================
// BMP Audit — silent U/T/A capture for Cursor hooks
// Handles: beforeSubmitPrompt, afterAgentThought,
//          afterAgentResponse
// =========================================================

$truncation_length = 240;

// --- Helpers ---

function extractProjectSlug(string $path): string
{
    if (preg_match(
        '#/projects/([^/]+)/#',
        $path,
        $matches
    )) {
        return $matches[1];
    }
    return 'unknown-project';
}

function truncateText(string $text, int $max): string
{
    $text = str_replace(["\n", "\r"], ' ', $text);
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max);
}

function smartTruncate(string $text, int $max): string
{
    $text = str_replace(["\n", "\r"], ' ', $text);
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    $head = mb_substr($text, 0, $max);

    $sentences = preg_split(
        '/(?<=[.!?])\s+/',
        $text,
        -1,
        PREG_SPLIT_NO_EMPTY
    );
    if (count($sentences) >= 2) {
        $tail = implode(
            ' ',
            array_slice($sentences, -2)
        );
    } else {
        $tail = end($sentences) ?: '';
    }

    if (mb_strlen($tail) > $max) {
        $tail = mb_substr($tail, 0, $max);
    }

    if ($head === $tail || str_contains($head, $tail)) {
        return $head;
    }

    return $head . ' [..] ' . $tail;
}

// --- Main ---

$raw = file_get_contents('php://stdin');
$payload = json_decode($raw, true);

if (
    $payload === null
    && json_last_error() !== JSON_ERROR_NONE
) {
    echo json_encode(['continue' => true]);
    exit(0);
}

$event = $payload['hook_event_name'] ?? '';

$typeMap = [
    'beforeSubmitPrompt' => 'U',
    'afterAgentThought'  => 'T',
    'afterAgentResponse' => 'A',
];

$type = $typeMap[$event] ?? null;
if ($type === null) {
    echo json_encode(['continue' => true]);
    exit(0);
}

$transcriptPath = $payload['transcript_path'] ?? '';
$slug = extractProjectSlug($transcriptPath);

$convId = $payload['conversation_id'] ?? '-';
$genId  = $payload['generation_id'] ?? '-';

if ($type === 'U') {
    $raw_text = $payload['prompt'] ?? '';
} else {
    $raw_text = $payload['text'] ?? '';
}

if ($type === 'A') {
    $content = smartTruncate($raw_text, $truncation_length);
} else {
    $content = truncateText($raw_text, $truncation_length);
}

$ts    = date('Y-m-d H:i:s');
$epoch = time();

if ($type === 'T') {
    $dur = $payload['duration_ms'] ?? 0;
    $entry = "[{$ts}]|{$epoch}|{$convId}"
        . "|{$genId}|T|{$dur}|{$content}";
} else {
    $entry = "[{$ts}]|{$epoch}|{$convId}"
        . "|{$genId}|{$type}|{$content}";
}

$logDir = ($_SERVER['HOME'] ?? getenv('HOME'))
    . '/.cursor/transcript_logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/' . $slug . '.txt';
file_put_contents($logFile, $entry . "\n", FILE_APPEND);

echo json_encode(['continue' => true]);
