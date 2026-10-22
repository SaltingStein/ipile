<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Control</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
    <main class="mx-auto max-w-5xl px-6 py-16">
        <h1 class="text-3xl font-bold">Access Control</h1>

        @if (session('status'))
            <div class="mt-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-8 grid gap-8 md:grid-cols-2">
            <section class="rounded border bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold">Roles</h2>
                <ul class="mt-4 space-y-2">
                    @foreach ($roles as $role)
                        <li class="rounded bg-slate-50 p-3">{{ $role->name }}</li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded border bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold">Permissions</h2>
                <ul class="mt-4 space-y-2">
                    @foreach ($permissions as $permission)
                        <li class="rounded bg-slate-50 p-3">{{ $permission->name }}</li>
                    @endforeach
                </ul>
            </section>
        </div>

        <form method="POST" action="{{ route('admin.access.update') }}" class="mt-10 rounded border bg-white p-6 shadow-sm">
            @csrf
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block">
                    <span class="mb-2 block text-sm font-medium">Role</span>
                    <input name="role" class="w-full rounded border border-slate-300 px-3 py-2" placeholder="admin" />
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium">Permission</span>
                    <input name="permission" class="w-full rounded border border-slate-300 px-3 py-2" placeholder="suspend_users" />
                </label>
            </div>

            <button type="submit" class="mt-6 rounded bg-slate-900 px-5 py-3 font-medium text-white hover:bg-slate-700">
                Save access rule
            </button>
        </form>
    </main>
</body>
</html>
