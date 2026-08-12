<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Cashier Inspector')</title>
    <style>
        /* Light palette. The dark block below only redefines these tokens, so
           every rule further down is written once and works in both themes. */
        :root {
            --bg: #f5f6f8;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --border: #e4e7ec;
            --text: #101828;
            --muted: #667085;
            --accent: #2563eb;
            --accent-soft: #eff6ff;
            --accent-border: #bfdbfe;
            --code-bg: #0b1021;
            --code-text: #d1d5db;
            --shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 1px 3px rgba(16, 24, 40, .1);
            --radius: 10px;

            --error-bg: #fee4e2;   --error-fg: #912018;   --error-border: #fecaca;
            --warning-bg: #fef0c7; --warning-fg: #93370d;
            --info-bg: #dbeafe;    --info-fg: #1e40af;
            --success-bg: #dcfce7; --success-fg: #166534;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --surface: #161b22;
                --surface-2: #1c2129;
                --border: #2a313c;
                --text: #e6edf3;
                --muted: #8b949e;
                --accent: #6ba1ff;
                --accent-soft: #16233b;
                --accent-border: #23385f;
                --code-bg: #0b1021;
                --code-text: #c9d1d9;
                --shadow: none;

                --error-bg: #3f1a1a;   --error-fg: #ff9a91;   --error-border: #5c2020;
                --warning-bg: #3a2a10; --warning-fg: #f3c164;
                --info-bg: #17253f;    --info-fg: #93b8ff;
                --success-bg: #13301f; --success-fg: #7ee2a8;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        code { font-size: 0.8125rem; }
        a { color: var(--accent); }

        .wrap { width: 100%; max-width: 1600px; margin: 0 auto; padding: 0 1.25rem; }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .topbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
            min-height: 3.5rem;
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }
        .brand {
            font-size: 0.9375rem;
            font-weight: 650;
            letter-spacing: -0.01em;
            color: var(--text);
            text-decoration: none;
        }

        main.wrap { padding-top: 1.5rem; padding-bottom: 3rem; }

        h1 { font-size: 1.125rem; margin: 0 0 0.25rem; letter-spacing: -0.01em; }
        h2 {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin: 1.75rem 0 0.625rem;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .card + .card { margin-top: 1rem; }
        .card-pad { padding: 1rem 1.25rem; }

        /* Tables scroll inside their card instead of widening the page. */
        .table-wrap { overflow-x: auto; border-radius: var(--radius); }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            text-align: left;
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            white-space: nowrap;
        }
        td.wrap-cell { white-space: normal; }
        tbody tr:last-child th, tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: var(--surface-2); }
        th {
            position: sticky;
            top: 0;
            background: var(--surface-2);
            text-transform: uppercase;
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: 0.04em;
        }
        th a { color: inherit; text-decoration: none; }
        th a:hover { color: var(--accent); }
        th.sorted a { color: var(--text); }
        .sort-arrow { color: var(--accent); font-size: 0.625rem; }
        td a { color: var(--accent); text-decoration: none; }
        td a:hover { text-decoration: underline; }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            background: var(--surface-2);
            color: var(--muted);
        }
        .severity-error { background: var(--error-bg); color: var(--error-fg); }
        .severity-warning { background: var(--warning-bg); color: var(--warning-fg); }
        .severity-info { background: var(--info-bg); color: var(--info-fg); }
        .severity-success { background: var(--success-bg); color: var(--success-fg); }

        .muted { color: var(--muted); }
        .empty { padding: 2.5rem 1.25rem; color: var(--muted); text-align: center; margin: 0; }

        .placeholder {
            padding: 0.875rem 1rem;
            background: var(--surface-2);
            border: 1px dashed var(--border);
            border-radius: var(--radius);
            color: var(--muted);
            margin: 0;
        }

        button, .button {
            font: inherit;
            cursor: pointer;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            padding: 0.375rem 0.75rem;
        }
        button:hover, .button:hover { background: var(--surface-2); }
        button.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            font-weight: 600;
        }
        button.primary:hover { filter: brightness(1.08); background: var(--accent); }
        button.link {
            border: 0;
            background: none;
            padding: 0;
            color: var(--accent);
        }
        button.link:hover { background: none; text-decoration: underline; }

        .controls {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.8125rem;
            color: var(--muted);
        }
        .controls a { text-decoration: none; }
        .controls a:hover { text-decoration: underline; }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            gap: 0.75rem;
            align-items: end;
        }
        .filters .field { display: flex; flex-direction: column; gap: 0.25rem; }
        /* Starts a fresh row, so the date range never splits across two. */
        .filters .row-start { grid-column: 1; }
        .filters label {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }
        .filters input, .filters select {
            font: inherit;
            font-size: 0.8125rem;
            width: 100%;
            padding: 0.4rem 0.5rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
        }
        .filters input:focus, .filters select:focus {
            outline: 2px solid var(--accent);
            outline-offset: -1px;
            border-color: var(--accent);
        }
        .filters .actions { display: flex; gap: 0.75rem; align-items: center; }
        .filters .clear { color: var(--muted); font-size: 0.8125rem; text-decoration: none; }
        .filters .clear:hover { text-decoration: underline; }

        .banner {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            margin-top: 1rem;
            padding: 0.625rem 1rem;
            background: var(--accent-soft);
            border: 1px solid var(--accent-border);
            border-radius: var(--radius);
            color: var(--accent);
        }
        .banner button { color: inherit; font-weight: 600; }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--border);
            font-size: 0.8125rem;
            color: var(--muted);
        }
        .pagination .pages { display: flex; align-items: center; gap: 1rem; }
        .pagination a { text-decoration: none; }
        .pagination a:hover { text-decoration: underline; }
        .pagination .disabled { opacity: 0.5; }

        dl {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
            gap: 1rem;
            margin: 0;
        }
        dt {
            color: var(--muted);
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.125rem;
        }
        dd { margin: 0; }

        .timeline { list-style: none; padding: 0; margin: 0; }
        .timeline li {
            padding: 0.375rem 0 0.375rem 1rem;
            margin-left: 0.25rem;
            border-left: 2px solid var(--border);
        }

        .finding {
            padding: 0.75rem 1rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-left: 3px solid var(--muted);
            border-radius: var(--radius);
        }
        .finding + .finding { margin-top: 0.5rem; }
        .finding p { margin: 0.375rem 0 0; }
        .finding.severity-error { border-left-color: var(--error-fg); }
        .finding.severity-warning { border-left-color: var(--warning-fg); }
        .finding.severity-info { border-left-color: var(--info-fg); }

        .exception {
            padding: 0.75rem 1rem;
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            border-radius: var(--radius);
            color: var(--error-fg);
        }
        .exception p { margin: 0.375rem 0 0; }
        .exception pre { white-space: pre-wrap; font-size: 0.75rem; margin: 0.5rem 0 0; }

        pre.payload {
            background: var(--code-bg);
            color: var(--code-text);
            padding: 0.875rem 1rem;
            border-radius: var(--radius);
            overflow-x: auto;
            font-size: 0.75rem;
            margin: 0.5rem 0 0;
        }
        /* Inside a table the JSON wraps rather than scrolling: the cell claims
           the row's leftover width, so the whole context stays readable. */
        td.context { width: 100%; }
        td.context pre.payload {
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            overflow-x: visible;
        }

        summary { cursor: pointer; color: var(--accent); }

        [x-cloak] { display: none !important; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ route('cashier-inspector.dashboard') }}">Cashier Inspector</a>
            @yield('topbar')
        </div>
    </header>

    <main class="wrap">
        @yield('content')
    </main>

    @stack('scripts')
    <script defer src="{{ route('cashier-inspector.assets.alpine') }}"></script>
</body>
</html>
