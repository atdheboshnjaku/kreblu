@extends('layouts.main')
@section('title')404 Not Found — {{ $site_name }}@endsection
@section('content')
<div class="empty-state">
    <h2>404 — Page Not Found</h2>
    <p>The page you're looking for doesn't exist. <a href="/">Go home</a>.</p>
</div>
@endsection