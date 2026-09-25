{{--
    Phase 0O.3 (ADR 0049 section 11): the app-owned replacement for the
    framework's `errors::minimal` layout. The stock layout inlines its CSS
    in <style> blocks, which the enforced Content-Security-Policy
    (`style-src 'self'`) blocks; this one loads the built stylesheet
    instead, so error pages stay styled with no inline style or script.
    Every framework error view (401, 403, 404, 419, 429, 500, 503) extends it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white font-sans text-slate-900 antialiased">
    <div class="flex min-h-screen items-center justify-center" role="main">
        <div class="mx-auto max-w-xl px-6">
            <div class="flex items-center">
                <h1 class="border-r border-slate-400 px-4 text-lg">@yield('code')</h1>
                <div class="ml-4 text-lg">@yield('message')</div>
            </div>
        </div>
    </div>
</body>
</html>
