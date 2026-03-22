@extends('layouts.main')
@section('title'){{ $post->title }} — {{ $site_name }}@endsection
@section('content')
<article class="single-post">
    <h1>{{ $post->title }}</h1>
    <div class="post-body">
        {!! kb_prepare_content($post->body ?? '') !!}
    </div>
</article>
@endsection