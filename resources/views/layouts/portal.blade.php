<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Underskriftrum' }} — Frankston</title>

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
            font-size: 1.125rem;
            line-height: 1.7;
            color: var(--ft-dark);
            background: var(--ft-pink);
            min-height: 100vh;
        }

        h1, h2, h3 { font-family: 'Playfair Display', Georgia, serif; color: var(--ft-black); }
        h1 { font-size: 2.5rem; font-weight: 700; line-height: 1.15; }
        h2 { font-size: 2rem; font-weight: 700; }
        h3 { font-size: 1.5rem; font-weight: 600; }

        a { color: var(--ft-blue); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .container { max-width: 1280px; margin: 0 auto; padding: 0 24px; }

        .sr-header {
            background: var(--ft-paper);
            border-bottom: 1px solid var(--ft-border);
            padding: 16px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        }
        .sr-header .container { display: flex; align-items: center; justify-content: space-between; }
        .sr-logo {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ft-black);
            text-decoration: none;
        }
        .sr-header-actions { display: flex; gap: 16px; align-items: center; }
        .sr-header-actions a { font-size: 0.95rem; font-weight: 600; color: var(--ft-dark); }

        .sr-main { padding: 48px 0 80px; min-height: calc(100vh - 140px); }

        .sr-footer {
            background: var(--ft-pink-dark);
            padding: 32px 0;
            text-align: center;
            font-size: 0.875rem;
            color: var(--ft-grey);
        }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--ft-black); color: white;
            padding: 14px 32px; border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 1rem; font-weight: 600;
            border: none; cursor: pointer; text-decoration: none; transition: all 0.3s ease;
        }
        .btn-primary:hover { background: #33302E; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); text-decoration: none; color: white; }

        .btn-outline {
            display: inline-flex; align-items: center; gap: 8px;
            background: transparent; color: var(--ft-black);
            padding: 14px 32px; border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 1rem; font-weight: 600;
            border: 1.5px solid var(--ft-black); cursor: pointer; text-decoration: none; transition: all 0.3s ease;
        }
        .btn-outline:hover { background: var(--ft-black); color: white; text-decoration: none; }

        .btn-danger {
            display: inline-flex; align-items: center;
            background: transparent; color: var(--ft-red);
            padding: 14px 32px; border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 1rem; font-weight: 600;
            border: 1.5px solid var(--ft-red); cursor: pointer; text-decoration: none; transition: all 0.3s ease;
        }
        .btn-danger:hover { background: var(--ft-red); color: white; }

        .card {
            background: var(--ft-paper); border: 1px solid var(--ft-border);
            border-radius: 8px; padding: 32px; transition: all 0.3s ease;
        }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 8px 32px rgba(0,0,0,0.08); }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 50px; font-size: 0.875rem; font-weight: 600; }
        .badge-green { background: #E8F5E9; color: var(--ft-green); }
        .badge-blue { background: #E3F2FD; color: var(--ft-blue); }
        .badge-yellow { background: #FFF8E1; color: #F57F17; }
        .badge-red { background: #FFEBEE; color: var(--ft-red); }
        .badge-gray { background: #F5F5F5; color: var(--ft-grey); }

        .form-input {
            width: 100%; padding: 10px 16px;
            border: 1px solid var(--ft-border); border-radius: 4px;
            font-family: 'Source Sans 3', sans-serif; font-size: 1rem; color: var(--ft-dark);
            transition: border-color 0.2s;
        }
        .form-input:focus { outline: none; border-color: var(--ft-blue); box-shadow: 0 0 0 3px rgba(13,118,128,0.1); }
        .form-label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--ft-black); }
        .form-group { margin-bottom: 20px; }
        .form-error { color: var(--ft-red); font-size: 0.875rem; margin-top: 4px; }

        .fade-up {
            opacity: 0; transform: translateY(30px);
            transition: opacity 0.7s cubic-bezier(0.16,1,0.3,1), transform 0.7s cubic-bezier(0.16,1,0.3,1);
        }
        .fade-up.visible { opacity: 1; transform: translateY(0); }

        @keyframes heroFloat {
            0%   { transform: scale(1) translate(0, 0); }
            33%  { transform: scale(1.03) translate(-1%, -1%); }
            66%  { transform: scale(1.02) translate(0.5%, -0.5%); }
            100% { transform: scale(1) translate(0, 0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .fade-up { opacity: 1; transform: none; transition: none; }
            img[style*="heroFloat"] { animation: none !important; }
        }

        @media (max-width: 768px) {
            h1 { font-size: 2rem; }
            .card { padding: 24px; }
            .sr-main { padding: 32px 0 48px; }
        }
    </style>

    @livewireStyles
</head>
<body>
    <header class="sr-header">
        <div class="container">
            <a href="{{ route('signing-room.portal.landing') }}" class="sr-logo">Frankston</a>
            <div class="sr-header-actions">
                @if(session('signing_room_email'))
                    <a href="{{ route('signing-room.portal.dashboard') }}">Mine dokumenter</a>
                @endif
            </div>
        </div>
    </header>

    <main class="sr-main">
        <div class="container">
            {{ $slot }}
        </div>
    </main>

    <footer class="sr-footer">
        <div class="container">
            <p>&copy; {{ date('Y') }} Frankston ApS &middot; sign.frankston.io</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            document.querySelectorAll('.fade-up').forEach(function(el) { observer.observe(el); });
        });
    </script>

    @livewireScripts
</body>
</html>
