<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Admin' }} — Underskriftrum</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Source+Sans+3:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --ft-pink: #FFF1E5;
            --ft-pink-light: #FFF7F0;
            --ft-pink-dark: #F2DFCE;
            --ft-paper: #FFFFFF;
            --ft-black: #1A1817;
            --ft-dark: #33302E;
            --ft-blue: #0D7680;
            --ft-claret: #990F3D;
            --ft-red: #CC0000;
            --ft-green: #09853C;
            --ft-border: #CCCAC2;
            --ft-grey: #757575;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Source Sans 3', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 1rem; line-height: 1.6;
            color: var(--ft-dark); background: var(--ft-pink-light);
            min-height: 100vh;
        }

        h1, h2, h3 { font-family: 'Playfair Display', Georgia, serif; color: var(--ft-black); }
        h1 { font-size: 2rem; font-weight: 700; }
        h2 { font-size: 1.5rem; font-weight: 700; }
        h3 { font-size: 1.25rem; font-weight: 600; }

        a { color: var(--ft-blue); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .admin-layout { display: flex; min-height: 100vh; }

        .admin-sidebar {
            width: 260px; background: var(--ft-paper);
            border-right: 1px solid var(--ft-border);
            padding: 24px 0; position: fixed; top: 0; bottom: 0; overflow-y: auto;
        }
        .admin-sidebar-logo {
            font-family: 'Playfair Display', serif; font-size: 1.25rem; font-weight: 700;
            color: var(--ft-black); text-decoration: none;
            padding: 0 24px 24px; display: block; border-bottom: 1px solid var(--ft-border);
        }
        .admin-sidebar-label {
            font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--ft-grey); padding: 24px 24px 8px;
        }
        .admin-sidebar nav { padding: 8px 0; }
        .admin-sidebar nav a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 24px; color: var(--ft-dark); font-weight: 500;
            text-decoration: none; transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .admin-sidebar nav a:hover { background: var(--ft-pink-light); text-decoration: none; }
        .admin-sidebar nav a.active {
            background: var(--ft-pink); color: var(--ft-black); font-weight: 600;
            border-left-color: var(--ft-claret);
        }

        .admin-content { flex: 1; margin-left: 260px; padding: 32px 40px; min-height: 100vh; }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--ft-black); color: white;
            padding: 12px 28px; border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 0.95rem; font-weight: 600;
            border: none; cursor: pointer; text-decoration: none; transition: all 0.3s;
        }
        .btn-primary:hover { background: #33302E; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); color: white; text-decoration: none; }

        .btn-outline {
            display: inline-flex; align-items: center; gap: 8px;
            background: transparent; color: var(--ft-black);
            padding: 12px 28px; border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 0.95rem; font-weight: 600;
            border: 1.5px solid var(--ft-black); cursor: pointer; text-decoration: none; transition: all 0.3s;
        }
        .btn-outline:hover { background: var(--ft-black); color: white; text-decoration: none; }

        .btn-sm { padding: 8px 16px; font-size: 0.875rem; }

        .btn-danger {
            display: inline-flex; align-items: center;
            background: transparent; color: var(--ft-red);
            padding: 12px 28px; border-radius: 4px; font-weight: 600;
            border: 1.5px solid var(--ft-red); cursor: pointer; transition: all 0.3s;
        }
        .btn-danger:hover { background: var(--ft-red); color: white; }

        .card { background: var(--ft-paper); border: 1px solid var(--ft-border); border-radius: 8px; padding: 24px; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
        .badge-green { background: #E8F5E9; color: var(--ft-green); }
        .badge-blue { background: #E3F2FD; color: var(--ft-blue); }
        .badge-yellow { background: #FFF8E1; color: #F57F17; }
        .badge-red { background: #FFEBEE; color: var(--ft-red); }
        .badge-gray { background: #F5F5F5; color: var(--ft-grey); }

        .form-input {
            width: 100%; padding: 10px 16px;
            border: 1px solid var(--ft-border); border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 0.95rem;
            color: var(--ft-dark); transition: border-color 0.2s;
        }
        .form-input:focus { outline: none; border-color: var(--ft-blue); box-shadow: 0 0 0 3px rgba(13,118,128,0.1); }
        .form-label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--ft-black); font-size: 0.95rem; }
        .form-group { margin-bottom: 16px; }
        .form-error { color: var(--ft-red); font-size: 0.8rem; margin-top: 4px; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            padding: 12px 16px; text-align: left; font-weight: 600; color: var(--ft-dark);
            background: var(--ft-pink-light); border-bottom: 1px solid var(--ft-border); font-size: 0.875rem;
        }
        .data-table td { padding: 16px; border-bottom: 1px solid var(--ft-border); }
        .data-table tr:hover td { background: var(--ft-pink-light); }
        .data-table tr { cursor: pointer; transition: background 0.2s; }

        @media (max-width: 1024px) {
            .admin-sidebar { width: 220px; }
            .admin-content { margin-left: 220px; padding: 24px; }
        }
        @media (max-width: 768px) {
            .admin-sidebar { display: none; }
            .admin-content { margin-left: 0; }
        }
    </style>

    @livewireStyles
</head>
<body>
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <a href="{{ route('signing-room.admin.index') }}" class="admin-sidebar-logo">Frankston</a>
            <div class="admin-sidebar-label">Underskriftrum</div>
            <nav>
                <a href="{{ route('signing-room.admin.index') }}"
                   class="{{ request()->routeIs('signing-room.admin.index') ? 'active' : '' }}">
                    Alle dokumenter
                </a>
                <a href="{{ route('signing-room.admin.create') }}"
                   class="{{ request()->routeIs('signing-room.admin.create') ? 'active' : '' }}">
                    + Nyt dokument
                </a>
            </nav>
            <div class="admin-sidebar-label">Indstillinger</div>
            <nav>
                <a href="{{ route('signing-room.admin.users') }}"
                   class="{{ request()->routeIs('signing-room.admin.users') ? 'active' : '' }}">
                    Administratorer
                </a>
            </nav>
        </aside>

        <main class="admin-content">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
