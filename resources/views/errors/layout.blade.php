<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/icon512rounded.png">
    <title>@yield('code') · LinguaCafe</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f2f3f5;
            color: #28272c;
        }

        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr;
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #dfe2e6;
            background: #fff;
        }

        .brand,
        .home-link {
            color: inherit;
            text-decoration: none;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
        }

        .home-link {
            font-size: 14px;
            font-weight: 600;
        }

        .content {
            display: grid;
            place-items: center;
            padding: 32px 24px;
        }

        .card {
            width: min(520px, 100%);
            padding: 40px;
            box-sizing: border-box;
            border: 1px solid #dfe2e6;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 14px 38px rgba(40, 39, 44, 0.08);
        }

        .code {
            margin: 0 0 12px;
            font-size: 48px;
            line-height: 1;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 24px;
        }

        p {
            margin: 0;
            color: #60656f;
            line-height: 1.7;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #1f1f23;
                color: #f5f5f7;
            }

            .nav,
            .card {
                background: #28272c;
                border-color: #3b3b42;
            }

            p {
                color: #bbbcc3;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <nav class="nav" aria-label="错误页导航">
        <a class="brand" href="/">LinguaCafe</a>
        <a class="home-link" href="/">返回首页</a>
    </nav>

    <main class="content">
        <section class="card" aria-labelledby="error-title">
            <p class="code">@yield('code')</p>
            <h1 id="error-title">@yield('title')</h1>
            <p>@yield('message')</p>
        </section>
    </main>
</div>
</body>
</html>
