<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Контроль выполнения процессов</title>
    <style>
        :root {
            color-scheme: light;
            --background: #f5f7fa;
            --surface: #ffffff;
            --border: #d8dee8;
            --text: #172033;
            --muted: #5e6879;
            --link: #175cd3;
            --failed: #fee4e2;
            --running: #f2f4f7;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main { width: min(1180px, calc(100% - 2rem)); margin: 2rem auto; }
        h1 { margin: 0 0 1.5rem; font-size: clamp(1.5rem, 3vw, 2rem); }
        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: .75rem;
            background: var(--surface);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .8rem 1rem; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
        th { color: var(--muted); font-size: .85rem; }
        tbody tr:last-child td { border-bottom: 0; }
        tr.process-failed { background: var(--failed); }
        tr.process-running { background: var(--running); }
        a { color: var(--link); }
        .empty { padding: 2rem; color: var(--muted); text-align: center; }
        nav { display: flex; justify-content: space-between; gap: 1rem; margin-top: 1rem; }
        nav span { color: var(--muted); }
        @media (max-width: 640px) {
            main { width: calc(100% - 1rem); margin: 1rem auto; }
            th, td { padding: .7rem; }
        }
    </style>
</head>
<body>
<main>
    <h1>Контроль выполнения процессов</h1>

    @if ($processes->isEmpty())
        <div class="table-wrap empty">Процессы ещё не запускались.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Дата процесса</th>
                    <th scope="col">Время запуска</th>
                    <th scope="col">Время выполнения</th>
                    <th scope="col">Идентификатор процесса</th>
                    <th scope="col">Статус</th>
                    <th scope="col">Файл</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($processes as $process)
                    <tr @class([
                        'process-failed' => $process->ps_id === \App\Enums\ProcessStatusId::Failed,
                        'process-running' => $process->ps_id === \App\Enums\ProcessStatusId::Running,
                    ])>
                        <td>{{ $process->rp_start_datetime->setTimezone(config('app.timezone'))->format('d.m.Y') }}</td>
                        <td>{{ $process->rp_start_datetime->setTimezone(config('app.timezone'))->format('H:i:s') }}</td>
                        <td>{{ $process->rp_exec_time === null ? '—' : $process->rp_exec_time.' с' }}</td>
                        <td>{{ $process->rp_pid }}</td>
                        <td>{{ $process->status->ps_name }}</td>
                        <td>
                            @if (isset($downloadableIds[$process->rp_id]))
                                <a href="{{ route('report-processes.download', $process) }}">
                                    {{ basename($process->rp_file_save_path) }}
                                </a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($processes->hasPages())
            <nav aria-label="Навигация по страницам">
                <span>
                    @if ($processes->previousPageUrl())
                        <a href="{{ $processes->previousPageUrl() }}" rel="prev">← Назад</a>
                    @endif
                </span>
                <span>Страница {{ $processes->currentPage() }} из {{ $processes->lastPage() }}</span>
                <span>
                    @if ($processes->nextPageUrl())
                        <a href="{{ $processes->nextPageUrl() }}" rel="next">Вперёд →</a>
                    @endif
                </span>
            </nav>
        @endif
    @endif
</main>
</body>
</html>
