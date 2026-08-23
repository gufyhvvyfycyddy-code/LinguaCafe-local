<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 38px; }
        body { color: #20242a; font-family: "Noto Sans SC", sans-serif; font-size: 10px; line-height: 1.55; }
        h1 { color: #172238; font-size: 20px; margin: 0 0 4px; }
        .meta { color: #667085; margin-bottom: 18px; }
        .event { border-left: 3px solid #5468e7; margin: 0 0 12px; padding: 8px 10px; page-break-inside: avoid; }
        .event.review { border-left-color: #6f42c1; }
        .title { font-size: 13px; font-weight: bold; }
        .badge { color: #475467; font-size: 9px; }
        .sense { margin: 3px 0; }
        .source { color: #475467; }
        .sentence { background: #f4f6f8; margin-top: 5px; padding: 5px 7px; }
        .current { border-top: 1px solid #e4e7ec; color: #667085; margin-top: 6px; padding-top: 4px; }
    </style>
</head>
<body>
    <h1>LinguaCafe 学习历史</h1>
    <div class="meta">
        {{ $meta['date_from'] }} 至 {{ $meta['date_to'] }} · {{ $meta['study_timezone'] }} · {{ count($rows) }} 个事件<br>
        当前记忆状态截至 {{ $meta['current_state_as_of'] }}
    </div>
    @forelse ($rows as $row)
        <section class="event {{ $row['event_type'] === 'review' ? 'review' : '' }}">
            <div class="title">{{ $row['lemma'] ?: ($row['surface_form'] ?: '未命名词义') }}</div>
            <div class="badge">
                {{ $row['event_type'] === 'learning_entry' ? '进入学习' : '复习 · '.$row['event_source'] }}
                @if ($row['rating']) · {{ $row['rating'] }} @endif
                · {{ $row['occurred_at'] }} · {{ $row['event_key'] }}
            </div>
            <div class="sense">{{ $row['sense_zh'] ?: ($row['sense_en'] ?: '暂无释义') }}</div>
            <div class="source">来源：{{ $row['chapter_title'] ?: '不可用' }} · {{ $row['source_accuracy'] }}</div>
            @if ($row['sentence_en']) <div class="sentence">{{ $row['sentence_en'] }}</div> @endif
            <div class="current">
                当前：{{ $row['current_lifecycle_state'] ?: '无卡片' }} / {{ $row['current_fsrs_state'] ?: '无 FSRS 状态' }}；
                复习 {{ $row['current_reps'] ?? '—' }} 次；到期 {{ $row['current_fsrs_due_at'] ?: '—' }}
            </div>
        </section>
    @empty
        <p>此范围内没有学习事件。</p>
    @endforelse
</body>
</html>
