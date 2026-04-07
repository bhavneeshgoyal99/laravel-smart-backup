@extends('smart-backup::layouts.app')

@section('content')
    <div class="hero">
        <div class="card">
            <p class="muted">Package Settings</p>
            <h1>Configuration at a glance.</h1>
            <p class="muted">This page reflects the current package configuration loaded into Laravel. It is intentionally read-only and light, so teams can inspect behavior without introducing a heavy settings frontend.</p>
        </div>

        <div class="card">
            <h2>Key Defaults</h2>
            <div class="meta-list">
                <div class="meta-row">
                    <span class="muted">Mode</span>
                    <strong>{{ $settings['mode'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Format</span>
                    <strong>{{ $settings['format'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Disk</span>
                    <strong>{{ $settings['disk'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Path</span>
                    <strong>{{ $settings['path'] }}</strong>
                </div>
                <div class="meta-row">
                    <span class="muted">Chunk size</span>
                    <strong>{{ $settings['chunk_size'] }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Schedule</h2>
            <div class="meta-list">
                @foreach ($settings['schedule'] as $key => $value)
                    <div class="meta-row">
                        <span class="muted">{{ str_replace('_', ' ', $key) }}</span>
                        <strong>{{ is_array($value) ? implode(', ', $value) : var_export($value, true) }}</strong>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <h2>Maintenance</h2>
            <div class="meta-list">
                @foreach ($settings['maintenance'] as $key => $value)
                    <div class="meta-row">
                        <span class="muted">{{ str_replace('_', ' ', $key) }}</span>
                        <strong>{{ var_export($value, true) }}</strong>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card">
        <h2>UI Route Settings</h2>
        <div class="meta-list">
            @foreach ($settings['ui'] as $key => $value)
                <div class="meta-row">
                    <span class="muted">{{ str_replace('_', ' ', $key) }}</span>
                    <strong>{{ is_array($value) ? implode(', ', $value) : var_export($value, true) }}</strong>
                </div>
            @endforeach
        </div>
    </div>
@endsection
