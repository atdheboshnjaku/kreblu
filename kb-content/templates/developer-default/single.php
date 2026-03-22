@extends('layouts.main')
@section('title'){{ $post->title }} — {{ $site_name }}@endsection
@section('content')
<article class="single-post">
    <h1>{{ $post->title }}</h1>
    <div class="post-meta">
        @isset($post->published_at)
            Published {{ $post->published_at }}
        @endisset
    </div>
    <div class="post-body">
        {!! $post->body !!}
    </div>
</article>
@endsection