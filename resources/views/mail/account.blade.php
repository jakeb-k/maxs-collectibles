<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title></head><body style="font-family:Arial,sans-serif;line-height:1.6">
<p>{{ config('app.name') }}</p><h1>{{ $title }}</h1><p><a href="{{ $link }}" rel="noreferrer">{{ $title }}</a></p>
<p>This link expires in {{ $minutes }} minutes. If you didn’t request it, you can ignore this message.</p>
</body></html>
