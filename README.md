# Cursor Chat Logger and Viewer

Silently tracks all Cursor AI interactions across every
project with timestamps, conversation IDs, user prompts,
agent thinking, and agent responses. No database required --
plain text logs per project.

**Copy 1 file. Edit 1 file. Done.**

No agent rules, no system prompts, no per-project config.
The hooks capture everything at the infrastructure level.

## What You Get

- Every user prompt (U), agent thinking block (T), and
  agent response (A) logged automatically
- Conversation and generation IDs to group related entries
- A self-contained web viewer with dashboard, heatmap,
  session grouping, and conversation-style display
- Markdown rendering in agent responses via Showdown.js
- Smart truncation that preserves the beginning and ending
  of long agent responses

## Requirements

- PHP in PATH (Laravel Herd, Homebrew, or any PHP install)
- Cursor IDE 2.5+

## Install

### 1. Copy audit.php

```bash
mkdir -p ~/.cursor/hooks
cp audit.php ~/.cursor/hooks/audit.php
```

### 2. Add hooks to ~/.cursor/hooks.json

If the file does not exist, copy the included one:

```bash
cp hooks.json ~/.cursor/hooks.json
```

If you already have a `hooks.json`, add the audit command to
all three hook arrays:

```json
{
  "version": 1,
  "hooks": {
    "beforeSubmitPrompt": [
      { "command": "php ~/.cursor/hooks/audit.php" }
    ],
    "afterAgentThought": [
      { "command": "php ~/.cursor/hooks/audit.php" }
    ],
    "afterAgentResponse": [
      { "command": "php ~/.cursor/hooks/audit.php" }
    ]
  }
}
```

### 3. Restart Cursor

Hooks are loaded on startup. Restart Cursor for the new
hooks to take effect.

That is it.

## Configuration

Open `~/.cursor/hooks/audit.php` and adjust the setting at
the top:

```php
$truncation_length = 240;
```

- Default `240` keeps logs compact (~one tweet per entry)
- Increase to `500`, `1000`, or higher for more context
- Set to `10000`+ if storage is not a concern and you want
  near-full captures

## Log Location

```
~/.cursor/transcript_logs/{project-slug}.txt
```

Project slug is derived from your workspace path. Example:
a project at `/Users/you/Herd/my-app` produces
`Users-you-Herd-my-app.txt`.

## Log Format

Three entry types, pipe-delimited:

```
[YYYY-MM-DD HH:MM:SS]|{epoch}|{conv_id}|{gen_id}|U|{user_prompt}
[YYYY-MM-DD HH:MM:SS]|{epoch}|{conv_id}|{gen_id}|T|{duration_ms}|{thinking_text}
[YYYY-MM-DD HH:MM:SS]|{epoch}|{conv_id}|{gen_id}|A|{agent_response}
```

| Type | Hook Event | Content |
|------|-----------|---------|
| U | beforeSubmitPrompt | What the user asked (truncated) |
| T | afterAgentThought | Agent thinking (truncated), with duration in ms |
| A | afterAgentResponse | Smart truncated: first N chars + last 2 sentences |

| Field | Purpose |
|-------|---------|
| Timestamp | Human-readable local time |
| Unix epoch | For timeblock math without date parsing |
| Conversation ID | Groups entries into chat sessions |
| Generation ID | Links T+A entries to their U prompt |

Example:

```
[2026-02-25 10:31:15]|1740522675|ec63...|b93b...|U|Steve is working on creating brand new endpoints for us...
[2026-02-25 10:34:20]|1740522860|ec63...|b93b...|T|29696|Steve created a brand new custom API endpoint for deposits!...
[2026-02-25 10:34:23]|1740522863|ec63...|b93b...|A|Clean across the board -- no stale references... [..] ...test adding lines to our deposit.
```

## Log Viewer

Run the self-contained viewer from anywhere:

```bash
php -S localhost:8899 log-viewer.php
```

Features:
- Dashboard with project cards, activity heatmap, and
  recent prompts
- Project detail with day-by-day breakdown and expandable
  sessions
- Conversation-style display: User prompt, Thinking
  duration, Agent response
- Markdown rendering in agent responses (code, bold,
  lists, tables)
- Smart truncation separator `[..]` rendered as a visual
  break
- Configurable gap threshold for session grouping
  (15m / 30m / 1h)
- Date range filtering

## Storage Estimates

At default truncation (240 chars), each interaction
produces roughly 750 bytes (U + T + A lines). Estimates
for heavy usage (50 interactions/day):

| Period | Size per project |
|--------|-----------------|
| Day | ~37 KB |
| Month | ~1.1 MB |
| Year | ~13 MB |

## Troubleshooting

- **Logs not appearing**: Verify `php` is in your PATH
  (`which php`). Herd users have this automatically.
- **Wrong timezone**: PHP uses system timezone by default.
  Set `date.timezone` in `php.ini` if needed.
- **Hooks not firing**: Restart Cursor after creating or
  modifying `~/.cursor/hooks.json`.
- **Missing thinking entries**: Not all models produce
  thinking blocks. The viewer handles U+A pairs without T.

## License

MIT

---

Made with pizza by BakeMorePies
