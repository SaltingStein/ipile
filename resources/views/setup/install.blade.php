{{-- Shown before the app is installed, so it needs no built assets, database or session. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Finish installing the site</title>
    <style>
        body { margin: 0; background: #020617; color: #e2e8f0; font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 42rem; margin: 0 auto; padding: 3rem 1.5rem; }
        .card { border: 1px solid #1e293b; border-radius: 1rem; background: #0f172a; padding: 2rem; }
        h1 { margin: 0 0 .5rem; font-size: 1.6rem; color: #f8fafc; }
        h2 { margin: 1.75rem 0 .5rem; font-size: 1.05rem; color: #f8fafc; }
        p, li { color: #cbd5e1; }
        ol { padding-left: 1.4rem; }
        li { margin: .5rem 0; }
        code { color: #6ee7b7; font-size: .9em; }
        pre { overflow-x: auto; border-radius: .5rem; background: #020617; padding: .75rem 1rem; color: #6ee7b7; }
        .eyebrow { margin: 0 0 1rem; font-size: .75rem; letter-spacing: .25em; text-transform: uppercase; color: #34d399; }
        .note { border-left: 3px solid #f59e0b; padding-left: .75rem; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <p class="eyebrow">First-time setup</p>
        <h1>Finish installing the site</h1>
        @if ($reason === 'app_key')
            <p>The site has no encryption key yet, so it cannot start.</p>
        @else
            <p>The site's database tables have not been created yet.</p>
        @endif
        <p>Run these steps on the server, in the site's folder. Only someone with access to the server can do this.</p>

        <h2>1. Settings file</h2>
        <ol>
            <li>If there is no <code>.env</code> file, copy <code>.env.example</code> to <code>.env</code>.</li>
            <li>In <code>.env</code>, set the database details (<code>DB_CONNECTION</code>, <code>DB_HOST</code>, <code>DB_DATABASE</code>, <code>DB_USERNAME</code>, <code>DB_PASSWORD</code>), <code>APP_URL</code> to this site's https address, <code>APP_ENV=production</code> and <code>APP_DEBUG=false</code>.</li>
            <li>Keep <code>.env</code> private: never commit it to git, and make it readable only by the web server's user.</li>
        </ol>

        <h2>2. Install</h2>
<pre>composer install --no-dev --optimize-autoloader
php artisan key:generate      # only when APP_KEY is empty
php artisan migrate --force
php artisan ipile:sync-permissions
php artisan storage:link
npm ci &amp;&amp; npm run build
php artisan optimize</pre>
        <p class="note">Run <code>php artisan key:generate</code> once only. A new key signs everyone out and makes saved integration keys unreadable.</p>

        <h2>3. Create the super admin</h2>
        <p>Reload this page. It opens the setup screen, which asks for a one-time setup key. Get the key on the server with:</p>
<pre>php artisan ipile:setup-key</pre>
        <p>Or create the super admin in the terminal instead: <code>php artisan ipile:setup</code>.</p>
    </div>
</main>
</body>
</html>
