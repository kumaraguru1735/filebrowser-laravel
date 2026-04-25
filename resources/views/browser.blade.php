@php
    // Dynamically locate the latest built assets (immune to vite hash changes)
    $distAssets = base_path('vendor/kumaraguru/filebrowser-laravel/resources/dist/assets');
    $assetFiles = is_dir($distAssets) ? scandir($distAssets) : [];

    $findLatest = function (string $prefix, string $ext) use ($assetFiles) {
        $matches = [];
        foreach ($assetFiles as $f) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-[A-Za-z0-9_-]+\.' . $ext . '$/', $f)) {
                $matches[] = $f;
            }
        }
        return $matches[0] ?? null;
    };

    $indexJs    = $findLatest('index', 'js') ?: 'index.js';
    $indexCss   = $findLatest('index', 'css') ?: 'index.css';
    $legacyJs   = $findLatest('index-legacy', 'js') ?: null;
    $polyfillJs = $findLatest('polyfills-legacy', 'js') ?: null;
    $rolldownJs = $findLatest('rolldown-runtime', 'js') ?: null;
    $dayjsJs    = $findLatest('dayjs', 'js') ?: null;
    $i18nJs     = $findLatest('i18n', 'js') ?: null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $name }}</title>
    <meta name="robots" content="noindex,nofollow" />
    <link rel="icon" type="image/svg+xml" href="{{ $staticURL }}/img/icons/favicon.svg" />
    <meta name="theme-color" content="#2979ff" />

    <script>
      window.FileBrowser = {
        Name: @json($name),
        DisableExternal: false,
        DisableUsedPercentage: false,
        BaseURL: @json($baseURL),
        StaticURL: @json($staticURL),
        ReCaptcha: false,
        ReCaptchaKey: "",
        Signup: false,
        Version: "laravel",
        NoAuth: true,
        AuthMethod: "noauth",
        LogoutPage: "/",
        LoginPage: false,
        Theme: "",
        EnableThumbs: true,
        ResizePreview: true,
        EnableExec: false,
        TusSettings: { chunkSize: 10485760, retryCount: 5 },
        HideLoginButton: true,
        CSS: false,
        User: {
            id: {{ auth()->id() ?? 0 }},
            username: @json(auth()->user()->name ?? 'user'),
            locale: "en",
            viewMode: "list",
            singleClick: false,
            perm: {
                admin: false,
                execute: false,
                create: true,
                rename: true,
                modify: true,
                delete: true,
                share: false,
                download: true
            },
            commands: [],
            lockPassword: false,
            hideDotfiles: false,
            dateFormat: false
        }
      };
      window.__prependStaticUrl = (url) => {
        return `${window.FileBrowser.StaticURL}/${url.replace(/^\/+/, "")}`;
      };
    </script>

    <style>
      #loading{position:fixed;top:0;left:0;width:100%;height:100%;background:#fff;z-index:9999;transition:.1s ease opacity}
      #loading.done{opacity:0}
      #loading .spinner{width:70px;text-align:center;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%)}
      #loading .spinner > div{width:18px;height:18px;background-color:#333;border-radius:100%;display:inline-block;animation:sk-bouncedelay 1.4s infinite ease-in-out both}
      #loading .spinner .bounce1{animation-delay:-.32s}
      #loading .spinner .bounce2{animation-delay:-.16s}
      @keyframes sk-bouncedelay{0%,80%,100%{transform:scale(0)}40%{transform:scale(1)}}
    </style>

    <script type="module" crossorigin src="{{ $staticURL }}/assets/{{ $indexJs }}"></script>
    @if($rolldownJs)<link rel="modulepreload" crossorigin href="{{ $staticURL }}/assets/{{ $rolldownJs }}">@endif
    @if($dayjsJs)<link rel="modulepreload" crossorigin href="{{ $staticURL }}/assets/{{ $dayjsJs }}">@endif
    @if($i18nJs)<link rel="modulepreload" crossorigin href="{{ $staticURL }}/assets/{{ $i18nJs }}">@endif
    <link rel="stylesheet" crossorigin href="{{ $staticURL }}/assets/{{ $indexCss }}">
</head>
<body>
    <div id="app"></div>
    <div id="loading">
      <div class="spinner">
        <div class="bounce1"></div>
        <div class="bounce2"></div>
        <div class="bounce3"></div>
      </div>
    </div>
    @if($polyfillJs)
    <script nomodule crossorigin id="vite-legacy-polyfill" src="{{ $staticURL }}/assets/{{ $polyfillJs }}"></script>
    @endif
    @if($legacyJs)
    <script nomodule crossorigin id="vite-legacy-entry" data-src="{{ $staticURL }}/assets/{{ $legacyJs }}">System.import(document.getElementById('vite-legacy-entry').getAttribute('data-src'))</script>
    @endif
</body>
</html>
