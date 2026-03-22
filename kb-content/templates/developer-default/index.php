@extends('layouts.main')
@section('title'){{ $site_name }}@endsection
@section('content')
@if($posts)
    @foreach($posts as $post)
    <article class="post-card">
        <h2><a href="/{{ $post->slug }}">{{ $post->title }}</a></h2>
        <div class="post-meta">
            @isset($post->published_at)
                {{ $post->published_at }}
            @endisset
        </div>
        @isset($post->body)
        <div class="post-body">
            {!! $post->body !!}
        </div>
        @endisset
    </article>
    @endforeach
@else
    <div class="empty-state">
        <h2>No posts yet</h2>
        <p>Create your first post from K Hub.</p>
    </div>
@endif
@endsection