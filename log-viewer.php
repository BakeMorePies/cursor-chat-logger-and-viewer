<?php
// =========================================================
// BMP Audit Log Viewer v2.0
// Self-contained transcript log viewer for Cursor AI
// Run: php -S localhost:8899 log-viewer.php
// Made with pizza by BakeMorePies
// =========================================================

$home = $_SERVER['HOME']
    ?? getenv('HOME')
    ?? posix_getpwuid(posix_getuid())['dir']
    ?? '/tmp';

$config = [
    'log_dir' => $home
        . '/.cursor/transcript_logs',
    'gap_min' => max(
        1,
        (int) ($_GET['gap'] ?? 30)
    ),
    'min_block_sec' => 300,
];

// --- Helpers ---

function parseLogFile(string $path): array
{
    $lines = file(
        $path,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );
    $entries = [];
    $pattern = '/^\[(.+?)\]\|(\d+)\|'
        . '([^|]+)\|([^|]+)\|(U|T|A)\|(.+)$/';

    foreach ($lines as $line) {
        if (!preg_match($pattern, $line, $m)) {
            continue;
        }

        $type = $m[5];
        $blob = $m[6];
        $durMs = null;
        $text = $blob;

        if ($type === 'T') {
            $pipePos = strpos($blob, '|');
            if ($pipePos !== false) {
                $durMs = (int) substr($blob, 0, $pipePos);
                $text = substr($blob, $pipePos + 1);
            }
        }

        $entries[] = [
            'ts'      => $m[1],
            'epoch'   => (int) $m[2],
            'date'    => substr($m[1], 0, 10),
            'time'    => substr($m[1], 11),
            'conv'    => $m[3],
            'gen'     => $m[4],
            'type'    => $type,
            'text'    => $text,
            'dur_ms'  => $durMs,
        ];
    }

    usort(
        $entries,
        fn($a, $b) => $a['epoch'] <=> $b['epoch']
    );
    return $entries;
}

function groupIntoInteractions(array $entries): array
{
    $interactions = [];
    $current = null;

    foreach ($entries as $e) {
        if ($e['type'] === 'U') {
            if ($current !== null) {
                $interactions[] = $current;
            }
            $current = [
                'user'     => $e,
                'thoughts' => [],
                'agent'    => null,
                'conv'     => $e['conv'],
                'epoch'    => $e['epoch'],
                'date'     => $e['date'],
            ];
        } elseif ($e['type'] === 'T') {
            if ($current !== null) {
                $current['thoughts'][] = $e;
            }
        } elseif ($e['type'] === 'A') {
            if ($current !== null) {
                $current['agent'] = $e;
            }
        }
    }

    if ($current !== null) {
        $interactions[] = $current;
    }

    return $interactions;
}

function buildTimeblocks(
    array $entries,
    int $gapSec,
    int $minSec
): array {
    if (empty($entries)) {
        return [];
    }

    $blocks = [];
    $cur = [
        'start'   => $entries[0]['epoch'],
        'end'     => $entries[0]['epoch'],
        'entries' => [$entries[0]],
        'convos'  => [$entries[0]['conv']],
    ];

    for ($i = 1; $i < count($entries); $i++) {
        $gap = $entries[$i]['epoch'] - $cur['end'];
        if ($gap > $gapSec) {
            $blocks[] = sealBlock($cur, $minSec);
            $cur = [
                'start'   => $entries[$i]['epoch'],
                'end'     => $entries[$i]['epoch'],
                'entries' => [$entries[$i]],
                'convos'  => [$entries[$i]['conv']],
            ];
        } else {
            $cur['end'] = $entries[$i]['epoch'];
            $cur['entries'][] = $entries[$i];
            $c = $entries[$i]['conv'];
            if (!in_array($c, $cur['convos'])) {
                $cur['convos'][] = $c;
            }
        }
    }
    $blocks[] = sealBlock($cur, $minSec);
    return $blocks;
}

function sealBlock(array $b, int $minSec): array
{
    $dur = $b['end'] - $b['start'];
    $b['duration'] = max($dur, $minSec);
    $b['date'] = date('Y-m-d', $b['start']);
    $b['t_start'] = date('g:i A', $b['start']);
    $b['t_end'] = date('g:i A', $b['end']);
    $uCount = 0;
    foreach ($b['entries'] as $e) {
        if ($e['type'] === 'U') {
            $uCount++;
        }
    }
    $b['count'] = $uCount;
    $b['interactions'] = groupIntoInteractions(
        $b['entries']
    );
    return $b;
}

function fmtDur(int $sec): string
{
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    if ($h > 0 && $m > 0) {
        return "{$h}h {$m}m";
    }
    return $h > 0 ? "{$h}h" : "{$m}m";
}

function fmtThinkDur(int $ms): string
{
    $sec = $ms / 1000;
    if ($sec < 1) {
        return round($sec * 1000) . 'ms';
    }
    return number_format($sec, 1) . 's';
}

function prettySlug(string $slug): string
{
    $patterns = [
        '/Herd-(.+)$/',
        '/Sites-(.+)$/',
        '/Documents-(.+)$/',
        '/Downloads-(.+)$/',
        '/Projects-(.+)$/',
        '/Code-(.+)$/',
        '/www-(.+)$/',
        '/html-(.+)$/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $slug, $m)) {
            return ucwords(
                str_replace('-', ' ', $m[1])
            );
        }
    }
    $stripped = preg_replace(
        '/^Users-[^-]+-/',
        '',
        $slug
    );
    return ucwords(
        str_replace('-', ' ', $stripped)
    );
}

function relTime(string $date): string
{
    $diff = time() - strtotime($date . ' 23:59:59');
    if ($diff < 86400) {
        return 'Today';
    }
    $days = floor($diff / 86400);
    if ($days === 1) {
        return 'Yesterday';
    }
    if ($days < 7) {
        return "{$days} days ago";
    }
    $weeks = floor($days / 7);
    if ($weeks < 5) {
        return "{$weeks}w ago";
    }
    return date('M j', strtotime($date));
}

function convColor(string $convId): string
{
    $colors = [
        'bg-indigo-500/20 text-indigo-300',
        'bg-emerald-500/20 text-emerald-300',
        'bg-amber-500/20 text-amber-300',
        'bg-rose-500/20 text-rose-300',
        'bg-cyan-500/20 text-cyan-300',
        'bg-violet-500/20 text-violet-300',
        'bg-orange-500/20 text-orange-300',
        'bg-teal-500/20 text-teal-300',
    ];
    $hash = crc32($convId);
    return $colors[abs($hash) % count($colors)];
}

function dotColor(string $convId): string
{
    $colors = [
        'bg-indigo-400',
        'bg-emerald-400',
        'bg-amber-400',
        'bg-rose-400',
        'bg-cyan-400',
        'bg-violet-400',
        'bg-orange-400',
        'bg-teal-400',
    ];
    $hash = crc32($convId);
    return $colors[abs($hash) % count($colors)];
}

function countByType(array $entries, string $t): int
{
    $n = 0;
    foreach ($entries as $e) {
        if ($e['type'] === $t) {
            $n++;
        }
    }
    return $n;
}

// --- Load Data ---

$projects = [];
$logDir = $config['log_dir'];

if (is_dir($logDir)) {
    foreach (glob($logDir . '/*.txt') as $file) {
        $slug = basename($file, '.txt');
        $all = parseLogFile($file);
        if (empty($all)) {
            continue;
        }

        $gapSec = $config['gap_min'] * 60;
        $blocks = buildTimeblocks(
            $all,
            $gapSec,
            $config['min_block_sec']
        );
        $totalSec = array_sum(
            array_column($blocks, 'duration')
        );

        $byDate = [];
        foreach ($all as $e) {
            $byDate[$e['date']][] = $e;
        }
        $blkByDate = [];
        foreach ($blocks as $b) {
            $blkByDate[$b['date']][] = $b;
        }

        $convos = array_unique(
            array_column($all, 'conv')
        );

        $uCount = countByType($all, 'U');

        $projects[$slug] = [
            'slug'       => $slug,
            'name'       => prettySlug($slug),
            'entries'    => $all,
            'blocks'     => $blocks,
            'by_date'    => $byDate,
            'blk_date'   => $blkByDate,
            'total'      => count($all),
            'prompts'    => $uCount,
            'blk_count'  => count($blocks),
            'total_sec'  => $totalSec,
            'first'      => $all[0]['date'],
            'last'       => end($all)['date'],
            'convos'     => count($convos),
        ];
    }
}

uasort(
    $projects,
    fn($a, $b) => strcmp($b['last'], $a['last'])
);

// --- Routing ---

$view = $_GET['view'] ?? 'dashboard';
$projKey = $_GET['project'] ?? null;
$dateFrom = $_GET['from'] ?? null;
$dateTo = $_GET['to'] ?? null;

$totPrompts = array_sum(
    array_column($projects, 'prompts')
);
$totBlocks = array_sum(
    array_column($projects, 'blk_count')
);
$totHours = array_sum(
    array_column($projects, 'total_sec')
) / 3600;

$filteredProject = null;
if ($projKey && isset($projects[$projKey])) {
    $p = $projects[$projKey];
    if ($dateFrom || $dateTo) {
        $from = $dateFrom ?: '1970-01-01';
        $to = $dateTo ?: '2099-12-31';
        $filtered = array_filter(
            $p['entries'],
            fn($e) => $e['date'] >= $from
                && $e['date'] <= $to
        );
        $filtered = array_values($filtered);
        $gapSec = $config['gap_min'] * 60;
        $fBlocks = buildTimeblocks(
            $filtered,
            $gapSec,
            $config['min_block_sec']
        );
        $fByDate = [];
        foreach ($filtered as $e) {
            $fByDate[$e['date']][] = $e;
        }
        $fBlkDate = [];
        foreach ($fBlocks as $b) {
            $fBlkDate[$b['date']][] = $b;
        }
        $filteredProject = [
            'entries'  => $filtered,
            'blocks'   => $fBlocks,
            'by_date'  => $fByDate,
            'blk_date' => $fBlkDate,
            'prompts'  => countByType($filtered, 'U'),
            'total_sec' => array_sum(
                array_column($fBlocks, 'duration')
            ),
        ];
    }
}

// Heatmap counts U entries only
$heatmap = [];
$today = date('Y-m-d');
for ($i = 89; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $count = 0;
    foreach ($projects as $p) {
        if (isset($p['by_date'][$d])) {
            $count += countByType(
                $p['by_date'][$d],
                'U'
            );
        }
    }
    $heatmap[$d] = $count;
}

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport"
  content="width=device-width, initial-scale=1.0">
<title>BMP Log Viewer</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/showdown@2.1.0/dist/showdown.min.js"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        mono: [
          'JetBrains Mono', 'Fira Code',
          'monospace'
        ]
      }
    }
  }
}
</script>
<style>
  body {
    background: #0c0f1a;
    color: #e2e8f0;
  }
  .card {
    background: #151926;
    border: 1px solid #1e293b;
    border-radius: 0.75rem;
  }
  .card-hover:hover {
    border-color: #6366f1;
    box-shadow: 0 0 20px rgba(99,102,241,0.08);
    transform: translateY(-1px);
    transition: all 0.2s ease;
  }
  .block-row { transition: all 0.15s ease; }
  .block-row:hover { background: #1a1f33; }
  .entry-list {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
  }
  .entry-list.open { max-height: 5000px; }
  .heat-cell {
    width: 12px; height: 12px;
    border-radius: 2px;
    transition: transform 0.1s;
  }
  .heat-cell:hover {
    transform: scale(1.5);
    z-index: 10;
  }
  .heat-0 { background: #1e293b; }
  .heat-1 { background: #312e81; }
  .heat-2 { background: #4338ca; }
  .heat-3 { background: #6366f1; }
  .heat-4 { background: #818cf8; }
  a { transition: color 0.15s; }
  .idle-bar {
    border-left: 2px dashed #334155;
    margin-left: 11px;
  }
  .stat-card {
    background: linear-gradient(
      135deg, #151926 0%, #1a1f33 100%
    );
    border: 1px solid #1e293b;
    border-radius: 0.75rem;
  }
  input[type="date"] {
    color-scheme: dark;
  }
  ::selection {
    background: #4338ca;
    color: white;
  }
  .ix-block {
    border-left: 2px solid #1e293b;
    margin-left: 3px;
  }
  .ix-block:hover {
    border-left-color: #334155;
  }
  .user-bubble {
    background: #1a2235;
    border: 1px solid #2d3a54;
    border-radius: 0.5rem;
  }
  .agent-bubble {
    background: #131a2b;
    border: 1px solid #1e293b;
    border-radius: 0.5rem;
  }
  .think-line {
    opacity: 0.65;
    transition: opacity 0.15s;
  }
  .think-line:hover { opacity: 1; }
  .truncation-sep {
    display: block;
    color: #475569;
    font-style: italic;
    font-size: 0.7rem;
    padding: 0.25rem 0;
  }
  .agent-md p { margin-bottom: 0.35rem; }
  .agent-md p:last-child { margin-bottom: 0; }
  .agent-md code {
    background: #1e293b;
    color: #a5b4fc;
    padding: 0.1rem 0.3rem;
    border-radius: 0.25rem;
    font-size: 0.8em;
    font-family: 'JetBrains Mono', 'Fira Code',
      monospace;
  }
  .agent-md pre {
    background: #0f172a;
    border: 1px solid #1e293b;
    border-radius: 0.375rem;
    padding: 0.5rem 0.75rem;
    overflow-x: auto;
    margin: 0.35rem 0;
    font-size: 0.8em;
  }
  .agent-md pre code {
    background: none;
    padding: 0;
    color: #cbd5e1;
  }
  .agent-md strong { color: #f1f5f9; }
  .agent-md ul, .agent-md ol {
    padding-left: 1.25rem;
    margin: 0.25rem 0;
  }
  .agent-md li { margin-bottom: 0.15rem; }
  .agent-md h1, .agent-md h2, .agent-md h3 {
    color: #f1f5f9;
    font-weight: 600;
    margin: 0.35rem 0 0.2rem;
  }
  .agent-md h1 { font-size: 1rem; }
  .agent-md h2 { font-size: 0.9rem; }
  .agent-md h3 { font-size: 0.85rem; }
  .agent-md a {
    color: #818cf8;
    text-decoration: underline;
  }
  .agent-md table {
    border-collapse: collapse;
    margin: 0.35rem 0;
    font-size: 0.85em;
    width: 100%;
  }
  .agent-md th, .agent-md td {
    border: 1px solid #1e293b;
    padding: 0.25rem 0.5rem;
  }
  .agent-md th {
    background: #1e293b;
    color: #f1f5f9;
  }
  .agent-md hr {
    border-color: #334155;
    margin: 0.5rem 0;
  }
  .agent-md blockquote {
    border-left: 3px solid #334155;
    padding-left: 0.75rem;
    color: #94a3b8;
    margin: 0.25rem 0;
  }
</style>
</head>
<body class="min-h-screen antialiased">

<nav class="border-b border-slate-800 px-6 py-4">
  <div class="max-w-7xl mx-auto flex items-center
    justify-between">
    <div class="flex items-center gap-3">
<?php if ($view === 'project' && $projKey): ?>
      <a href="?" class="text-slate-400
        hover:text-white text-sm">
        &larr; Dashboard
      </a>
      <span class="text-slate-600">/</span>
      <h1 class="text-lg font-semibold text-white">
        <?= htmlspecialchars(
            $projects[$projKey]['name'] ?? $projKey
        ) ?>
      </h1>
<?php else: ?>
      <h1 class="text-lg font-semibold text-white">
        BMP Log Viewer
      </h1>
      <span class="text-xs text-slate-500
        font-mono ml-2">
        v2.0
      </span>
<?php endif; ?>
    </div>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-slate-500">
        Gap:
        <a href="?<?= http_build_query(
            array_merge(
                $_GET, ['gap' => 15]
            )
        ) ?>"
          class="<?= $config['gap_min'] === 15
            ? 'text-indigo-400' : 'text-slate-400'
          ?> hover:text-indigo-300">15m</a>
        <a href="?<?= http_build_query(
            array_merge(
                $_GET, ['gap' => 30]
            )
        ) ?>"
          class="<?= $config['gap_min'] === 30
            ? 'text-indigo-400' : 'text-slate-400'
          ?> hover:text-indigo-300">30m</a>
        <a href="?<?= http_build_query(
            array_merge(
                $_GET, ['gap' => 60]
            )
        ) ?>"
          class="<?= $config['gap_min'] === 60
            ? 'text-indigo-400' : 'text-slate-400'
          ?> hover:text-indigo-300">1h</a>
      </span>
      <span class="text-slate-600">|</span>
      <span class="text-slate-500 font-mono text-xs">
        <?= htmlspecialchars($logDir) ?>
      </span>
    </div>
  </div>
</nav>

<main class="max-w-7xl mx-auto px-6 py-8">

<?php if (empty($projects)): ?>

<div class="text-center py-20">
  <div class="text-4xl text-slate-600 mb-4">
    No logs found
  </div>
  <p class="text-slate-400 max-w-md mx-auto">
    No transcript log files were found in
    <code class="text-indigo-400">
      ~/.cursor/transcript_logs/
    </code>.
    Set up the global audit hook to start
    capturing logs.
  </p>
  <a href="https://github.com/BakeMorePies/cursor-chat-logger-and-viewer"
    class="inline-block mt-6 text-indigo-400
      hover:text-indigo-300 text-sm">
    View setup instructions &rarr;
  </a>
</div>

<?php elseif (
    $view === 'project'
    && $projKey
    && isset($projects[$projKey])
): ?>

<?php
    $proj = $projects[$projKey];
    $src = $filteredProject ?? $proj;
    $dates = array_keys($src['blk_date'] ?? []);
    rsort($dates);
    $dispSec = $filteredProject
        ? $filteredProject['total_sec']
        : $proj['total_sec'];
    $dispPrompts = $filteredProject
        ? $filteredProject['prompts']
        : $proj['prompts'];
?>

<div class="mb-8">
  <form method="get"
    class="flex items-end gap-4 flex-wrap">
    <input type="hidden" name="view" value="project">
    <input type="hidden" name="project"
      value="<?= htmlspecialchars($projKey) ?>">
    <input type="hidden" name="gap"
      value="<?= $config['gap_min'] ?>">
    <div>
      <label class="block text-xs text-slate-500
        mb-1">From</label>
      <input type="date" name="from"
        value="<?= htmlspecialchars(
            $dateFrom ?? ''
        ) ?>"
        class="bg-slate-800 border border-slate-700
          rounded-lg px-3 py-2 text-sm text-slate-200
          focus:border-indigo-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-xs text-slate-500
        mb-1">To</label>
      <input type="date" name="to"
        value="<?= htmlspecialchars(
            $dateTo ?? ''
        ) ?>"
        class="bg-slate-800 border border-slate-700
          rounded-lg px-3 py-2 text-sm text-slate-200
          focus:border-indigo-500 focus:outline-none">
    </div>
    <button type="submit" class="bg-indigo-600
      hover:bg-indigo-500 text-white text-sm px-4
      py-2 rounded-lg transition">Filter</button>
<?php if ($dateFrom || $dateTo): ?>
    <a href="?view=project&project=<?=
      urlencode($projKey) ?>&gap=<?=
      $config['gap_min'] ?>"
      class="text-slate-400 hover:text-white
        text-sm py-2">Clear</a>
<?php endif; ?>
  </form>
</div>

<div class="grid grid-cols-4 gap-4 mb-8">
  <div class="stat-card p-4">
    <div class="text-2xl font-bold text-white">
      <?= fmtDur($dispSec) ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Total Time
    </div>
  </div>
  <div class="stat-card p-4">
    <div class="text-2xl font-bold text-white">
      <?= $dispPrompts ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Interactions
    </div>
  </div>
  <div class="stat-card p-4">
    <div class="text-2xl font-bold text-white">
      <?= count($src['blocks'] ?? []) ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Sessions
    </div>
  </div>
  <div class="stat-card p-4">
    <div class="text-2xl font-bold text-white">
      <?= count($dates) ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Active Days
    </div>
  </div>
</div>

<?php if (empty($dates)): ?>
<div class="text-center py-12 text-slate-500">
  No entries match the selected date range.
</div>
<?php endif; ?>

<?php foreach ($dates as $date):
    $dayBlocks = $src['blk_date'][$date] ?? [];
    $dayEntries = $src['by_date'][$date] ?? [];
    $daySec = array_sum(
        array_column($dayBlocks, 'duration')
    );
    $dayPrompts = countByType($dayEntries, 'U');
    $dayName = date('l', strtotime($date));
    $dayFmt = date('F j, Y', strtotime($date));
    $isToday = $date === date('Y-m-d');
?>
<div class="card mb-4">
  <div class="flex items-center justify-between
    px-5 py-4 border-b border-slate-800">
    <div class="flex items-center gap-3">
<?php if ($isToday): ?>
      <span class="text-xs bg-indigo-500/20
        text-indigo-300 px-2 py-0.5
        rounded-full">TODAY</span>
<?php endif; ?>
      <h3 class="font-semibold text-white">
        <?= $dayName ?>
      </h3>
      <span class="text-sm text-slate-400">
        <?= $dayFmt ?>
      </span>
    </div>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-slate-400">
        <?= $dayPrompts ?> interaction<?=
          $dayPrompts !== 1 ? 's' : '' ?>
      </span>
      <span class="font-mono text-indigo-400">
        <?= fmtDur($daySec) ?>
      </span>
    </div>
  </div>

  <div class="px-5 py-3">
<?php foreach ($dayBlocks as $bi => $block): ?>
<?php if ($bi > 0):
    $idleGap = $block['start']
        - $dayBlocks[$bi - 1]['end'];
    if ($idleGap > 0):
?>
    <div class="idle-bar pl-6 py-2 my-1">
      <span class="text-xs text-slate-600 italic">
        <?= fmtDur($idleGap) ?> idle
      </span>
    </div>
<?php endif; endif; ?>

    <div class="block-row rounded-lg mb-1">
      <button onclick="toggleBlock(this)"
        class="w-full text-left px-4 py-3 flex
          items-center justify-between group">
        <div class="flex items-center gap-3">
          <span class="text-slate-500 group-hover:
            text-slate-300 text-xs transition">
            &#9654;
          </span>
          <span class="font-mono text-sm
            text-slate-200">
            <?= $block['t_start'] ?>
            &ndash;
            <?= $block['t_end'] ?>
          </span>
          <span class="font-mono text-sm
            text-indigo-400">
            <?= fmtDur($block['duration']) ?>
          </span>
          <span class="text-xs text-slate-500">
            <?= $block['count'] ?>
            interaction<?= $block['count'] !== 1
                ? 's' : '' ?>
          </span>
        </div>
        <div class="flex items-center gap-1">
<?php foreach ($block['convos'] as $cv):
    $short = substr($cv, 0, 8);
?>
          <span class="text-xs px-2 py-0.5
            rounded-full font-mono
            <?= convColor($cv) ?>">
            <?= $short ?>
          </span>
<?php endforeach; ?>
        </div>
      </button>

      <div class="entry-list px-4 pb-3">
<?php foreach (
    $block['interactions'] as $ix
): ?>
        <div class="ix-block pl-4 py-3 mb-2">

          <div class="user-bubble px-3 py-2 mb-2">
            <div class="flex items-center gap-2
              mb-1">
              <span class="text-xs font-semibold
                text-sky-400">User</span>
              <span class="font-mono text-xs
                text-slate-500">
                <?= $ix['user']['time'] ?>
              </span>
              <span class="text-xs px-1.5 py-0.5
                rounded font-mono
                <?= convColor($ix['conv']) ?>">
                <?= substr($ix['conv'], 0, 8) ?>
              </span>
            </div>
            <p class="text-sm text-slate-300
              leading-relaxed">
              <?= htmlspecialchars(
                  $ix['user']['text']
              ) ?>
            </p>
          </div>

<?php foreach ($ix['thoughts'] as $th): ?>
          <div class="think-line flex items-start
            gap-2 px-3 py-1.5 mb-1">
            <span class="text-xs text-violet-400
              whitespace-nowrap mt-0.5">
              Thinking (<?= fmtThinkDur(
                  $th['dur_ms'] ?? 0
              ) ?>)
            </span>
            <p class="text-xs text-slate-500
              leading-relaxed">
              <?= htmlspecialchars($th['text']) ?>
            </p>
          </div>
<?php endforeach; ?>

<?php if ($ix['agent']): ?>
          <div class="agent-bubble px-3 py-2 mt-1">
            <div class="flex items-center gap-2
              mb-1">
              <span class="text-xs font-semibold
                text-emerald-400">Agent</span>
              <span class="font-mono text-xs
                text-slate-500">
                <?= $ix['agent']['time'] ?>
              </span>
            </div>
            <div class="agent-md text-sm text-slate-300
              leading-relaxed"
              data-raw="<?= htmlspecialchars(
                  $ix['agent']['text'],
                  ENT_QUOTES
              ) ?>"></div>
          </div>
<?php endif; ?>

        </div>
<?php endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>


<?php else: ?>

<div class="grid grid-cols-4 gap-4 mb-8">
  <div class="stat-card p-5">
    <div class="text-3xl font-bold text-white">
      <?= count($projects) ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Projects
    </div>
  </div>
  <div class="stat-card p-5">
    <div class="text-3xl font-bold text-white">
      <?= $totPrompts ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Interactions
    </div>
  </div>
  <div class="stat-card p-5">
    <div class="text-3xl font-bold text-white">
      <?= $totBlocks ?>
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Sessions
    </div>
  </div>
  <div class="stat-card p-5">
    <div class="text-3xl font-bold text-white">
      <?= number_format($totHours, 1) ?>h
    </div>
    <div class="text-xs text-slate-400 mt-1">
      Estimated Hours
    </div>
  </div>
</div>

<div class="card p-5 mb-8">
  <h2 class="text-sm font-semibold text-slate-400
    mb-3">Activity &mdash; Last 90 Days</h2>
  <div class="flex flex-wrap gap-[3px]">
<?php foreach ($heatmap as $d => $count):
    $lvl = 0;
    if ($count >= 1) $lvl = 1;
    if ($count >= 3) $lvl = 2;
    if ($count >= 6) $lvl = 3;
    if ($count >= 10) $lvl = 4;
?>
    <div class="heat-cell heat-<?= $lvl ?>"
      title="<?= $d ?>: <?= $count ?> prompts">
    </div>
<?php endforeach; ?>
  </div>
  <div class="flex items-center gap-2 mt-3
    text-xs text-slate-500">
    <span>Less</span>
    <div class="heat-cell heat-0"></div>
    <div class="heat-cell heat-1"></div>
    <div class="heat-cell heat-2"></div>
    <div class="heat-cell heat-3"></div>
    <div class="heat-cell heat-4"></div>
    <span>More</span>
  </div>
</div>

<h2 class="text-sm font-semibold text-slate-400
  mb-4">Projects</h2>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4
  mb-8">
<?php foreach ($projects as $slug => $p): ?>
  <a href="?view=project&project=<?=
    urlencode($slug)
  ?>&gap=<?= $config['gap_min'] ?>"
    class="card card-hover p-5 block">
    <div class="flex items-start justify-between
      mb-3">
      <div>
        <h3 class="font-semibold text-white text-lg">
          <?= htmlspecialchars($p['name']) ?>
        </h3>
        <p class="text-xs text-slate-500 font-mono
          mt-0.5">
          <?= htmlspecialchars($slug) ?>
        </p>
      </div>
      <span class="text-xs text-slate-500">
        <?= relTime($p['last']) ?>
      </span>
    </div>
    <div class="grid grid-cols-4 gap-3 text-center">
      <div>
        <div class="text-lg font-bold
          text-indigo-400">
          <?= fmtDur($p['total_sec']) ?>
        </div>
        <div class="text-xs text-slate-500">
          Time
        </div>
      </div>
      <div>
        <div class="text-lg font-bold text-white">
          <?= $p['prompts'] ?>
        </div>
        <div class="text-xs text-slate-500">
          Interactions
        </div>
      </div>
      <div>
        <div class="text-lg font-bold text-white">
          <?= $p['blk_count'] ?>
        </div>
        <div class="text-xs text-slate-500">
          Sessions
        </div>
      </div>
      <div>
        <div class="text-lg font-bold text-white">
          <?= $p['convos'] ?>
        </div>
        <div class="text-xs text-slate-500">
          Convos
        </div>
      </div>
    </div>

    <div class="mt-3 flex gap-[2px] h-2
      rounded-full overflow-hidden">
<?php
    $uByDate = [];
    foreach ($p['by_date'] as $dd => $ee) {
        $uByDate[$dd] = countByType($ee, 'U');
    }
    $maxPerDay = max(1, max($uByDate ?: [1]));
    $recent14 = array_slice(
        array_keys($uByDate),
        -14
    );
    foreach ($recent14 as $rd):
        $cnt = $uByDate[$rd] ?? 0;
        $pct = ($cnt / $maxPerDay) * 100;
        $opacity = max(20, min(100, $pct));
?>
      <div class="flex-1 bg-indigo-500
        rounded-sm"
        style="opacity: <?=
          $opacity / 100
        ?>;"
        title="<?= $rd ?>: <?=
          $cnt
        ?> interactions"></div>
<?php endforeach; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>

<h2 class="text-sm font-semibold text-slate-400
  mb-4">Recent Activity</h2>
<div class="card">
<?php
    $recentU = [];
    foreach ($projects as $slug => $p) {
        $uEntries = array_filter(
            $p['entries'],
            fn($e) => $e['type'] === 'U'
        );
        foreach (
            array_slice(
                array_values($uEntries),
                -5
            ) as $e
        ) {
            $e['_proj'] = $p['name'];
            $e['_slug'] = $slug;
            $recentU[] = $e;
        }
    }
    usort(
        $recentU,
        fn($a, $b) => $b['epoch'] <=> $a['epoch']
    );
    $recentU = array_slice($recentU, 0, 15);
?>
<?php foreach ($recentU as $r): ?>
  <div class="flex items-start gap-3 px-5 py-3
    border-b border-slate-800/50 last:border-0">
    <div class="w-2 h-2 rounded-full flex-shrink-0
      mt-1.5 <?= dotColor($r['conv']) ?>"></div>
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2 mb-0.5">
        <a href="?view=project&project=<?=
          urlencode($r['_slug'])
        ?>&gap=<?= $config['gap_min'] ?>"
          class="text-xs text-indigo-400
            hover:text-indigo-300">
          <?= htmlspecialchars($r['_proj']) ?>
        </a>
        <span class="text-xs text-slate-600">
          <?= $r['date'] ?>
          <?= $r['time'] ?>
        </span>
        <span class="text-xs text-sky-500">
          User
        </span>
      </div>
      <p class="text-sm text-slate-300 truncate">
        <?= htmlspecialchars($r['text']) ?>
      </p>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php endif; ?>

</main>

<footer class="border-t border-slate-800 mt-12
  py-6 text-center">
  <p class="text-xs text-slate-600">
    Made with &#127829; by BakeMorePies
  </p>
</footer>

<script>
function toggleBlock(btn) {
  const list = btn.nextElementSibling;
  const arrow = btn.querySelector('span');
  list.classList.toggle('open');
  if (list.classList.contains('open')) {
    arrow.innerHTML = '&#9660;';
  } else {
    arrow.innerHTML = '&#9654;';
  }
}

(function() {
  if (typeof showdown === 'undefined') return;
  var conv = new showdown.Converter({
    tables: true,
    strikethrough: true,
    ghCodeBlocks: true,
    simpleLineBreaks: true,
    simplifiedAutoLink: true,
    openLinksInNewWindow: true,
    ghCompatibleHeaderId: true,
    literalMidWordUnderscores: true
  });
  document.querySelectorAll('.agent-md').forEach(
    function(el) {
      var raw = el.getAttribute('data-raw');
      if (!raw) return;
      raw = raw.replace(
        /\[\.\.]/g,
        '\n\n<span class="truncation-sep">'
          + '[..]</span>\n\n'
      );
      el.innerHTML = conv.makeHtml(raw);
    }
  );
})();
</script>
</body>
</html>
