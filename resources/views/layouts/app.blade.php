@php($routePrefix = config('backup.ui.name_prefix', 'smart-backup.'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Smart Backup' }}</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #12202f;
            --muted: #5d6b79;
            --line: #d8e1e8;
            --panel: #ffffff;
            --bg: #f4f1ea;
            --accent: #0c7c59;
            --accent-soft: #daf1e7;
            --danger: #b9382f;
            --danger-soft: #f8dfdb;
            --shadow: 0 20px 45px rgba(18, 32, 47, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(12, 124, 89, 0.08), transparent 24rem),
                linear-gradient(180deg, #fbf9f4 0%, var(--bg) 100%);
        }

        a { color: inherit; text-decoration: none; }

        .shell {
            max-width: 1680px;
            margin: 0 auto;
            padding: 24px 12px 48px;
        }

        .nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
        }

        .brand {
            font-size: 1.35rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .nav-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nav-link,
        .button {
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.95rem;
        }

        .nav-link.active,
        .button.primary {
            background: var(--ink);
            color: #fff;
            border-color: var(--ink);
        }

        .button {
            cursor: pointer;
            font: inherit;
        }

        .button.danger {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.3fr 0.9fr;
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(216, 225, 232, 0.9);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 22px;
        }

        .hero h1, .card h2, .card h3 {
            margin-top: 0;
        }

        .muted {
            color: var(--muted);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stack { display: grid; gap: 18px; }

        .flash {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
        }

        .flash.success {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .flash.error {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            padding: 14px;
            border-radius: 18px;
            background: #f8fafb;
            border: 1px solid var(--line);
        }

        .stat strong {
            display: block;
            font-size: 1.4rem;
            margin-bottom: 4px;
        }

        form.inline { display: inline; }
        form.stack { display: grid; gap: 12px; }

        input, select, textarea {
            width: 100%;
            padding: 11px 12px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            font: inherit;
            color: inherit;
        }

        label {
            display: grid;
            gap: 8px;
            font-size: 0.94rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 92px;
            padding: 7px 14px;
            border-radius: 999px;
            border: 1px solid transparent;
            background: #eef3f6;
            color: var(--ink);
            font-size: 0.86rem;
            line-height: 1;
        }

        .badge.success {
            background: var(--accent-soft);
            color: var(--accent);
            border-color: rgba(12, 124, 89, 0.18);
        }

        .badge.failed {
            background: var(--danger-soft);
            color: var(--danger);
            border-color: rgba(185, 56, 47, 0.18);
        }

        .meta-list {
            display: grid;
            gap: 12px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 12px;
            border-bottom: 1px dashed var(--line);
        }

        @media (max-width: 900px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .shell {
                padding: 20px 12px 36px;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="nav">
        <a href="{{ route($routePrefix . 'backups.index') }}" class="brand">Smart Backup</a>
        <div class="nav-links">
            <a href="{{ route($routePrefix . 'backups.index') }}" class="nav-link {{ request()->routeIs($routePrefix . 'backups.index') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route($routePrefix . 'settings') }}" class="nav-link {{ request()->routeIs($routePrefix . 'settings') ? 'active' : '' }}">Settings</a>
        </div>
    </div>

    @if (session('status'))
        <div class="flash success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif

    @yield('content')
</div>
</body>
</html>
